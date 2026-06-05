use zed_extension_api::{self as zed, settings::LspSettings, LanguageServerId, Result};

const LANGUAGE_SERVER_ID: &str = "laravel";

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

        let binary_settings = LspSettings::for_worktree(LANGUAGE_SERVER_ID, worktree)?.binary;
        let Some(binary) = binary_settings else {
            return Err(
                "Laravel LSP server binary path is not configured in Zed settings".to_string(),
            );
        };
        let Some(command) = binary.path else {
            return Err(
                "Laravel LSP server binary path is not configured in Zed settings".to_string(),
            );
        };

        Ok(zed::Command {
            command,
            args: binary.arguments.unwrap_or_default(),
            env: worktree.shell_env(),
        })
    }
}

zed::register_extension!(LaravelExtension);
