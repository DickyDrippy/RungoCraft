# Security checklist

- Do not commit `config/database.php` and `config/integrations.php`.
- Do not commit wallets, SSL private keys, backups, SQL dumps or uploaded user files.
- Use HTTPS in production.
- Restrict public access to `app`, `config`, `sql`, `views`, `scripts`, `wallet` and dotfiles.
- Keep uploads limited to safe file types.
- Rotate API keys if they were ever exposed in a public repository.
