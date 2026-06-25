use std::{
    cmp::Ordering,
    fs,
    io::ErrorKind,
    path::{Path, PathBuf},
    time::{Duration, SystemTime, UNIX_EPOCH},
};

use zed_extension_api::{self as zed, settings::LspSettings, LanguageServerId, Result};

const LANGUAGE_SERVER_ID: &str = "laravel";
const LARAVEL_LSP_REPOSITORY: &str = "laravel/lsp";
const MANAGED_BINARY_DIR: &str = "laravel-lsp";
const LAST_UPDATE_CHECK_FILENAME: &str = ".last-update-check";
const UPDATE_CHECK_INTERVAL: Duration = Duration::from_secs(2 * 60 * 60);

struct LaravelExtension;

impl zed::Extension for LaravelExtension {
    fn new() -> Self {
        Self
    }

    fn language_server_command(
        &mut self,
        language_server_id: &LanguageServerId,
        worktree: &zed::Worktree,
    ) -> Result<zed::Command> {
        if language_server_id.as_ref() != LANGUAGE_SERVER_ID {
            return Err(format!("unknown language server: {language_server_id}"));
        }

        if let Some(binary) = LspSettings::for_worktree(LANGUAGE_SERVER_ID, worktree)?.binary {
            if let Some(command) = binary.path {
                return Ok(zed::Command {
                    command,
                    args: binary.arguments.unwrap_or_default(),
                    env: worktree.shell_env(),
                });
            }
        }

        let command = match install_or_find_laravel_lsp(language_server_id) {
            Ok(command) => {
                zed::set_language_server_installation_status(
                    language_server_id,
                    &zed::LanguageServerInstallationStatus::None,
                );
                command
            }
            Err(error) => {
                zed::set_language_server_installation_status(
                    language_server_id,
                    &zed::LanguageServerInstallationStatus::Failed(error.clone()),
                );
                return Err(error);
            }
        };

        Ok(zed::Command {
            command,
            args: Vec::new(),
            env: worktree.shell_env(),
        })
    }

    fn language_server_initialization_options(
        &mut self,
        language_server_id: &LanguageServerId,
        worktree: &zed::Worktree,
    ) -> Result<Option<zed::serde_json::Value>> {
        if language_server_id.as_ref() != LANGUAGE_SERVER_ID {
            return Err(format!("unknown language server: {language_server_id}"));
        }

        Ok(LspSettings::for_worktree(LANGUAGE_SERVER_ID, worktree)?.initialization_options)
    }
}

fn install_or_find_laravel_lsp(language_server_id: &LanguageServerId) -> Result<String> {
    let managed_binary_dir = Path::new(MANAGED_BINARY_DIR);
    let update_check_due = should_check_for_update(managed_binary_dir);
    let existing_binary_path = match find_existing_laravel_lsp_binary(managed_binary_dir) {
        Ok(binary_path) => binary_path,
        Err(error) if !update_check_due => return Err(error),
        Err(_) => None,
    };

    if !update_check_due {
        if let Some(binary_path) = existing_binary_path {
            return Ok(binary_path);
        }
    }

    zed::set_language_server_installation_status(
        language_server_id,
        &zed::LanguageServerInstallationStatus::CheckingForUpdate,
    );

    match install_latest_laravel_lsp(language_server_id, managed_binary_dir) {
        Ok(binary_path) => Ok(binary_path),
        Err(error) => {
            if let Some(binary_path) = existing_binary_path {
                write_last_update_check(managed_binary_dir);
                Ok(binary_path)
            } else {
                Err(error)
            }
        }
    }
}

fn install_latest_laravel_lsp(
    language_server_id: &LanguageServerId,
    managed_binary_dir: &Path,
) -> Result<String> {
    let release = zed::latest_github_release(
        LARAVEL_LSP_REPOSITORY,
        zed::GithubReleaseOptions {
            require_assets: true,
            pre_release: false,
        },
    )?;

    let asset_name = server_asset_name(&release.version)?;
    let asset = release
        .assets
        .iter()
        .find(|asset| asset.name == asset_name)
        .ok_or_else(|| {
            format!(
                "Laravel LSP release {} does not include an asset named {asset_name}",
                release.version
            )
        })?;

    let binary_dir = managed_binary_dir.join(&release.version);
    let binary_path = binary_dir.join(&asset_name);
    let binary_path_string = binary_path.to_string_lossy().into_owned();

    if fs::metadata(&binary_path).is_ok() {
        cleanup_old_laravel_lsp_versions(&release.version, managed_binary_dir)?;
        write_last_update_check(managed_binary_dir);
        return Ok(binary_path_string);
    }

    fs::create_dir_all(&binary_dir).map_err(|error| {
        format!(
            "failed to create Laravel LSP binary directory {}: {error}",
            binary_dir.display()
        )
    })?;

    zed::set_language_server_installation_status(
        language_server_id,
        &zed::LanguageServerInstallationStatus::Downloading,
    );

    zed::download_file(
        &asset.download_url,
        &binary_path_string,
        zed::DownloadedFileType::Uncompressed,
    )
    .inspect_err(|_| {
        let _ = fs::remove_file(&binary_path);
    })?;
    zed::make_file_executable(&binary_path_string).inspect_err(|_| {
        let _ = fs::remove_file(&binary_path);
    })?;

    cleanup_old_laravel_lsp_versions(&release.version, managed_binary_dir)?;
    write_last_update_check(managed_binary_dir);

    Ok(binary_path_string)
}

fn server_asset_name(version: &str) -> Result<String> {
    let (os, architecture) = zed::current_platform();

    let architecture = match architecture {
        zed::Architecture::Aarch64 => "arm64",
        zed::Architecture::X8664 => "x64",
        zed::Architecture::X86 => {
            return Err("Laravel LSP does not provide standalone binaries for x86".to_string());
        }
    };

    let os = match os {
        zed::Os::Mac => "darwin",
        zed::Os::Linux => "linux",
        zed::Os::Windows => "win32.exe",
    };

    Ok(format!("server-{version}-{architecture}-{os}"))
}

fn should_check_for_update(managed_binary_dir: &Path) -> bool {
    let Some(last_checked_at) = read_last_update_check(managed_binary_dir) else {
        return true;
    };
    let Some(now) = current_unix_timestamp() else {
        return true;
    };

    update_check_is_stale(last_checked_at, now)
}

fn update_check_is_stale(last_checked_at: u64, now: u64) -> bool {
    now.checked_sub(last_checked_at)
        .map(|elapsed| elapsed >= UPDATE_CHECK_INTERVAL.as_secs())
        .unwrap_or(true)
}

fn read_last_update_check(managed_binary_dir: &Path) -> Option<u64> {
    fs::read_to_string(last_update_check_path(managed_binary_dir))
        .ok()?
        .trim()
        .parse()
        .ok()
}

fn write_last_update_check(managed_binary_dir: &Path) {
    let Some(now) = current_unix_timestamp() else {
        return;
    };

    if fs::create_dir_all(managed_binary_dir).is_err() {
        return;
    }

    let _ = fs::write(last_update_check_path(managed_binary_dir), now.to_string());
}

fn last_update_check_path(managed_binary_dir: &Path) -> PathBuf {
    managed_binary_dir.join(LAST_UPDATE_CHECK_FILENAME)
}

fn current_unix_timestamp() -> Option<u64> {
    SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .ok()
        .map(|duration| duration.as_secs())
}

fn find_existing_laravel_lsp_binary(managed_binary_dir: &Path) -> Result<Option<String>> {
    find_existing_laravel_lsp_binary_with_asset_name(managed_binary_dir, server_asset_name)
}

fn find_existing_laravel_lsp_binary_with_asset_name(
    managed_binary_dir: &Path,
    server_asset_name: impl Fn(&str) -> Result<String>,
) -> Result<Option<String>> {
    let entries = match fs::read_dir(managed_binary_dir) {
        Ok(entries) => entries,
        Err(error) if error.kind() == ErrorKind::NotFound => return Ok(None),
        Err(error) => {
            return Err(format!(
                "failed to read Laravel LSP binary directory {}: {error}",
                managed_binary_dir.display()
            ));
        }
    };

    let mut binary_paths = Vec::new();
    for entry in entries {
        let entry = entry.map_err(|error| {
            format!("failed to read Laravel LSP binary directory entry: {error}")
        })?;
        if !entry.path().is_dir() {
            continue;
        }

        let version = entry.file_name().to_string_lossy().into_owned();
        let asset_name = server_asset_name(&version)?;
        let binary_path = managed_binary_dir.join(&version).join(asset_name);
        if fs::metadata(&binary_path).is_ok() {
            binary_paths.push((version, binary_path.to_string_lossy().into_owned()));
        }
    }

    Ok(binary_paths
        .into_iter()
        .max_by(|(left_version, _), (right_version, _)| {
            compare_version_names(left_version, right_version)
        })
        .map(|(_, binary_path)| binary_path))
}

fn compare_version_names(left: &str, right: &str) -> Ordering {
    numeric_version_key(left)
        .cmp(&numeric_version_key(right))
        .then_with(|| left.cmp(right))
}

fn numeric_version_key(version: &str) -> Vec<u64> {
    version
        .split(|character: char| !character.is_ascii_digit())
        .filter(|part| !part.is_empty())
        .filter_map(|part| part.parse().ok())
        .collect()
}

fn cleanup_old_laravel_lsp_versions(active_version: &str, managed_binary_dir: &Path) -> Result<()> {
    let entries = match fs::read_dir(managed_binary_dir) {
        Ok(entries) => entries,
        Err(error) if error.kind() == ErrorKind::NotFound => return Ok(()),
        Err(error) => {
            return Err(format!(
                "failed to read Laravel LSP binary directory {}: {error}",
                managed_binary_dir.display()
            ));
        }
    };

    for entry in entries {
        let entry = entry.map_err(|error| {
            format!("failed to read Laravel LSP binary directory entry: {error}")
        })?;
        let file_name = entry.file_name();
        let file_name = file_name.to_string_lossy();
        if file_name == active_version || file_name == LAST_UPDATE_CHECK_FILENAME {
            continue;
        }

        let path = entry.path();
        if path.is_dir() {
            fs::remove_dir_all(&path).map_err(|error| {
                format!(
                    "failed to remove old Laravel LSP binary directory {}: {error}",
                    path.display()
                )
            })?;
        } else {
            fs::remove_file(&path).map_err(|error| {
                format!(
                    "failed to remove old Laravel LSP binary file {}: {error}",
                    path.display()
                )
            })?;
        }
    }

    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::{
        env, process,
        sync::atomic::{AtomicUsize, Ordering as AtomicOrdering},
    };

    static NEXT_TEST_DIR: AtomicUsize = AtomicUsize::new(0);

    #[test]
    fn update_check_is_stale_after_two_hours() {
        assert!(!update_check_is_stale(1_000, 1_000));
        assert!(!update_check_is_stale(
            1_000,
            1_000 + UPDATE_CHECK_INTERVAL.as_secs() - 1
        ));
        assert!(update_check_is_stale(
            1_000,
            1_000 + UPDATE_CHECK_INTERVAL.as_secs()
        ));
    }

    #[test]
    fn future_update_check_timestamp_is_stale() {
        assert!(update_check_is_stale(2_000, 1_000));
    }

    #[test]
    fn version_name_comparison_uses_numeric_segments() {
        assert_eq!(
            compare_version_names("v1.10.0", "v1.2.0"),
            Ordering::Greater
        );
        assert_eq!(compare_version_names("v2.0.0", "v10.0.0"), Ordering::Less);
    }

    #[test]
    fn finds_newest_existing_laravel_lsp_binary() {
        let test_dir = TestDir::new("find-existing-binary");
        let managed_binary_dir = test_dir.path();
        let older_binary_path = create_managed_binary(managed_binary_dir, "v1.2.0");
        let newer_binary_path = create_managed_binary(managed_binary_dir, "v1.10.0");
        fs::create_dir_all(managed_binary_dir.join("v2.0.0")).unwrap();

        let binary_path =
            find_existing_laravel_lsp_binary_with_asset_name(managed_binary_dir, test_asset_name)
                .unwrap()
                .unwrap();

        assert_ne!(binary_path, older_binary_path.to_string_lossy());
        assert_eq!(binary_path, newer_binary_path.to_string_lossy());
    }

    #[test]
    fn installed_binary_lookup_requires_expected_asset_name() {
        let test_dir = TestDir::new("expected-asset-name");
        let managed_binary_dir = test_dir.path();
        fs::create_dir_all(managed_binary_dir.join("v1.0.0")).unwrap();
        fs::write(
            managed_binary_dir
                .join("v1.0.0")
                .join(test_asset_name("v1.0.0").unwrap()),
            "binary",
        )
        .unwrap();

        let binary_path =
            find_existing_laravel_lsp_binary_with_asset_name(managed_binary_dir, |_version| {
                Ok("different-platform-asset".to_string())
            })
            .unwrap();

        assert!(binary_path.is_none());
    }

    #[test]
    fn cleanup_preserves_active_version_and_update_check_timestamp() {
        let test_dir = TestDir::new("cleanup");
        let managed_binary_dir = test_dir.path();
        let active_binary_path = create_managed_binary(managed_binary_dir, "v1.10.0");
        let old_binary_path = create_managed_binary(managed_binary_dir, "v1.2.0");
        let update_check_path = last_update_check_path(managed_binary_dir);
        let orphaned_file_path = managed_binary_dir.join("server-old");
        fs::write(&update_check_path, "1234").unwrap();
        fs::write(&orphaned_file_path, "old").unwrap();

        cleanup_old_laravel_lsp_versions("v1.10.0", managed_binary_dir).unwrap();

        assert!(active_binary_path.exists());
        assert!(update_check_path.exists());
        assert!(!old_binary_path.exists());
        assert!(!old_binary_path.parent().unwrap().exists());
        assert!(!orphaned_file_path.exists());
    }

    fn create_managed_binary(managed_binary_dir: &Path, version: &str) -> PathBuf {
        let asset_name = test_asset_name(version).unwrap();
        let binary_path = managed_binary_dir.join(version).join(asset_name);
        fs::create_dir_all(binary_path.parent().unwrap()).unwrap();
        fs::write(&binary_path, "binary").unwrap();
        binary_path
    }

    fn test_asset_name(version: &str) -> Result<String> {
        Ok(format!("server-{version}-test"))
    }

    struct TestDir {
        path: PathBuf,
    }

    impl TestDir {
        fn new(name: &str) -> Self {
            let index = NEXT_TEST_DIR.fetch_add(1, AtomicOrdering::Relaxed);
            let path = env::temp_dir().join(format!(
                "zed-laravel-lsp-{name}-{}-{}-{index}",
                process::id(),
                current_unix_timestamp().unwrap_or_default()
            ));
            let _ = fs::remove_dir_all(&path);
            fs::create_dir_all(&path).unwrap();

            Self { path }
        }

        fn path(&self) -> &Path {
            &self.path
        }
    }

    impl Drop for TestDir {
        fn drop(&mut self) {
            let _ = fs::remove_dir_all(&self.path);
        }
    }
}

zed::register_extension!(LaravelExtension);
