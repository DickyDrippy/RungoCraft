# Hosting deployment

Upload the project to the server and keep production configuration files on the server only.

Typical update command:

```bash
sudo rsync -av --delete \
  --exclude='config/database.php' \
  --exclude='config/integrations.php' \
  --exclude='uploads/' \
  ./RungoCraft/ /var/www/RungoCraft/
```

After deployment restart PHP-FPM and reload the web server.
