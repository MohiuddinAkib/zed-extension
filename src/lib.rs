use std::{fs, io::ErrorKind};

use zed_extension_api::{self as zed, settings::LspSettings, LanguageServerId, Result};

const LANGUAGE_SERVER_ID: &str = "laravel";
const LARAVEL_LSP_REPOSITORY: &str = "laravel/lsp";
const MANAGED_BINARY_DIR: &str = "laravel-lsp";

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
}

fn install_or_find_laravel_lsp(language_server_id: &LanguageServerId) -> Result<String> {
    zed::set_language_server_installation_status(
        language_server_id,
        &zed::LanguageServerInstallationStatus::CheckingForUpdate,
    );

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

    let binary_dir = format!("{MANAGED_BINARY_DIR}/{}", release.version);
    let binary_path = format!("{binary_dir}/{asset_name}");

    if fs::metadata(&binary_path).is_ok() {
        cleanup_old_laravel_lsp_versions(&release.version)?;
        return Ok(binary_path);
    }

    fs::create_dir_all(&binary_dir).map_err(|error| {
        format!("failed to create Laravel LSP binary directory {binary_dir}: {error}")
    })?;

    zed::set_language_server_installation_status(
        language_server_id,
        &zed::LanguageServerInstallationStatus::Downloading,
    );

    zed::download_file(
        &asset.download_url,
        &binary_path,
        zed::DownloadedFileType::Uncompressed,
    )?;
    zed::make_file_executable(&binary_path)?;

    cleanup_old_laravel_lsp_versions(&release.version)?;

    Ok(binary_path)
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

fn cleanup_old_laravel_lsp_versions(active_version: &str) -> Result<()> {
    let entries = match fs::read_dir(MANAGED_BINARY_DIR) {
        Ok(entries) => entries,
        Err(error) if error.kind() == ErrorKind::NotFound => return Ok(()),
        Err(error) => {
            return Err(format!(
                "failed to read Laravel LSP binary directory {MANAGED_BINARY_DIR}: {error}"
            ));
        }
    };

    for entry in entries {
        let entry = entry.map_err(|error| {
            format!("failed to read Laravel LSP binary directory entry: {error}")
        })?;
        if entry.file_name().to_string_lossy() == active_version {
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

zed::register_extension!(LaravelExtension);
