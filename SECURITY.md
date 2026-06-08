SECURITY CHECKLIST — The Satoshi Faucet

This file lists pragmatic steps to protect `support.html` and the site on shared cPanel hosting. Follow what's possible for your hosting plan; flag items you can't perform and request host support.

1) Harden cPanel access
- Enable Two-Factor Authentication (cPanel → Security → Two-Factor Authentication).
- Use a unique, strong password (password manager recommended).
- Remove or disable plain FTP accounts (use SFTP only).
- Restrict cPanel/IP access if your host supports it.

2) Use SFTP/SSH keys or Git deployment
- Generate an SSH key locally and add the public key to cPanel (Security → SSH Access).
- Prefer SFTP with keys or cPanel Git deployment for updates instead of web File Manager.

Commands (run locally / via SSH):
```bash
ssh-keygen -t ed25519 -C "you@example.com"
# copy ~/.ssh/id_ed25519.pub into cPanel SSH Access
```

3) Lock `support.html` file permissions
- Make the file read-only from the server:
```bash
chmod 444 ~/public_html/support.html
```
- If host allows, make it immutable (ask host if needed):
```bash
chattr +i ~/public_html/support.html
```
Note: `chattr` may require root or specific host support on shared hosting.

4) Prevent web-based writes
- Audit and remove any admin/upload scripts that can write files in `public_html`.
- Deny PHP execution in upload directories with an `.htaccess` (place inside upload dirs):
```
<FilesMatch "\.(php|phtml|php3|php4|php5|php7)$">
  Deny from all
</FilesMatch>
```

5) Rotate credentials and limit accounts
- Rotate cPanel, FTP, and API passwords after securing access.
- Remove unused FTP/SSH accounts.
- Use least-privilege accounts for deployments.

6) Backups & integrity monitoring
- Enable daily backups in cPanel and keep off-site copies (download weekly snapshots).
- Add a simple integrity check script and cronjob to email or log if `support.html` changes.
Example (simple):
```bash
# first run: create baseline
cd ~/public_html
md5sum support.html > ~/support.md5
# cron job compares and emails you when different (simplified)
```

7) Incident response (if page is modified)
- Immediately restore `support.html` from a recent backup.
- Rotate all passwords/API keys and revoke SSH keys if needed.
- Check cPanel logs for suspicious access and contact host support.

8) Long-term improvements
- Move dynamic addresses to a config file outside webroot and restrict access.
- Use Git-based deploys so changes must originate from your development machine.
- Consider migrating to a VPS or managed host once funds/traffic grow.

Need help applying any steps now? If you have SSH access, I can attempt to set `chmod 444` for `support.html` and add a simple integrity-check cron entry — confirm you want me to proceed and that SSH is enabled for your account.