# 部署指南

給負責架設與更新 BabyGo 正式網站的人。假設是一台 Linux 主機（Nginx + PHP-FPM），網站放在 `/var/www/babygo`。

## 需要準備

- PHP 8.4 以上，並啟用 `mbstring`、`intl`、`zip`、`xml`、`fileinfo`、`pdo_sqlite`（用 MySQL 時改成 `pdo_mysql`）
- Composer 2
- Nginx 或 Apache，以及 HTTPS 憑證（例如 Let's Encrypt）
- SQLite，或 MySQL／MariaDB

不需要 Node.js：前台與後台的樣式都已經放在 `public/`，沒有前端建置步驟。

需要設定 cron 才會自動備份和清除一年前的操作紀錄（步驟見下方）。目前不需要佇列 worker，因為系統還沒有背景工作。

## 第一次架設

```bash
cd /var/www/babygo
git clone https://github.com/10994015/pet-crm.git .

composer install --no-dev --optimize-autoloader

cp .env.production.example .env
nano .env                       # 填好 APP_URL、資料庫與 OWNER_* 項目
php artisan key:generate

# 只有使用 SQLite 時需要：先建立資料庫檔案
mkdir -p /var/www/babygo/shared
touch /var/www/babygo/shared/database.sqlite

php artisan migrate --force
php artisan db:seed --force     # 建立業主帳號與入門價目表
php artisan storage:link

# 這一步一定要放最後：快取之後 .env 就不會再被讀取
php artisan optimize

chown -R www-data:www-data storage bootstrap/cache /var/www/babygo/shared
```

### 設定排程

每天的自動備份和舊紀錄清除靠 Laravel 排程執行，要在主機加一筆 cron（用 `crontab -u www-data -e`）：

```cron
* * * * * cd /var/www/babygo && php artisan schedule:run >> /dev/null 2>&1
```

`db:seed` 如果沒有設定 `OWNER_PASSWORD`，會產生隨機密碼並顯示在畫面上，請先記下來。業主登入後請改掉密碼，再把 `.env` 裡的 `OWNER_PASSWORD` 刪除。

### Nginx 設定

網站根目錄**必須指向 `public/`**，否則 `.env` 等檔案可能被直接下載。

```nginx
server {
    listen 443 ssl;
    server_name example.com;
    root /var/www/babygo/public;
    index index.php;
    client_max_body_size 20M;

    # ssl_certificate 和 ssl_certificate_key 依憑證工具設定

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

Apache 可以直接使用專案內的 `public/.htaccess`，一樣要把網站根目錄指向 `public/`。

### 放在代理或 Cloudflare 後面時

如果主機前面還有反向代理或 Cloudflare，要讓系統認得真正的客人 IP 和 https，否則預約的次數限制會把所有客人算成同一人。請在 `bootstrap/app.php` 設定（`at` 換成代理的 IP）：

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->trustProxies(at: ['代理的 IP']);
})
```

## 上線後檢查

1. 開 `https://你的網址/up`，應該顯示正常。
2. 開一個不存在的網址，應該看到 BabyGo 的「找不到這個頁面」，而不是 Laravel 預設畫面。
3. 用業主帳號登入 `/admin`。
4. 到「系統設定」填好**電話、地址、LINE**：隱私權聲明的聯絡方式來自這裡，沒填的話客人會找不到行使權利的管道。
5. 實際走一次線上預約，確認後台收得到。
6. 確認 `.env` 是 `APP_DEBUG=false`。
7. 開 `https://你的網址/robots.txt`，應該列出 `Sitemap:`；如果顯示 `Disallow: /`，代表 `APP_ENV` 不是 `production`，搜尋引擎不會收錄網站。
8. 到 [Google Search Console](https://search.google.com/search-console) 驗證網站並提交 `https://你的網址/sitemap.xml`，再用 LINE 傳一次網址，確認預覽圖正常。
9. 在「系統設定」按「立即備份」，確認備份成功並能下載。隔天再回來看，「最新備份」的時間應該是當天凌晨，表示排程有在跑。

## 從舊系統匯入資料

只做一次，要在正式開放預約之前做。先在 `.env` 填好 `BIBIDOG_DB_*`，再清除設定快取，否則新填的值讀不到：

```bash
php artisan config:clear
php artisan bibidog:import --dry-run   # 試跑：看筆數和金額核對，不會寫入
php artisan bibidog:import             # 金額核對相符後才正式匯入
php artisan optimize
```

## 更新版本

```bash
php artisan down

git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize

php artisan up
```

維護期間客人會看到「系統維護中」的頁面。

更新前請先執行 `php artisan babygo:backup`。

## 備份與還原

每天凌晨 3 點會自動備份資料庫和上傳的照片，存成 `storage/app/backups/babygo-日期-時間.zip`，保留 14 天（至少留下最新一份）。也可以手動執行：

```bash
php artisan babygo:backup
```

業主可以在後台「系統設定」按「立即備份」或「下載最新備份」，並查看最新備份的時間；超過兩天沒有新備份會顯示紅字。照片很多時，建議改用上面的指令，避免網頁逾時。

用 MySQL／MariaDB 時，主機要裝有 `mysqldump`；不在 PATH 裡就設定 `BACKUP_MYSQLDUMP`。

**這些備份預設和網站在同一台主機，主機損毀就會一起不見。** 請至少做一項：

- 把 `BACKUP_PATH` 指到掛載的雲端或網路資料夾
- 用 rclone 等工具定期複製到別處
- 定期從後台下載，存到別的地方

備份不包含 `.env`，請另外保管一份，尤其是 `APP_KEY`。備份檔有客戶個資且**沒有加密**，存放時要控管權限。

### 還原

先在測試環境練習一次，確認備份真的可用：

```bash
php artisan down
unzip babygo-20261109-030000.zip -d /tmp/restore

# SQLite：覆蓋資料庫檔案
cp /tmp/restore/database.sqlite /var/www/babygo/shared/database.sqlite

# MySQL／MariaDB：改用這行匯入
# mysql -u babygo -p babygo < /tmp/restore/database.sql

# 還原照片
cp -r /tmp/restore/uploads/. storage/app/public/

chown -R www-data:www-data storage /var/www/babygo/shared
rm -rf /tmp/restore
php artisan up
```

## 忘記後台密碼

- **員工：** 業主在「系統設定 > 員工帳號」直接設定新密碼。
- **業主：** 在主機上執行以下指令，畫面會顯示新密碼：

```bash
php artisan babygo:reset-password owner
```

設定好真正的寄信服務（`MAIL_MAILER` 不是 `log`）後，登入頁會出現「忘記密碼」連結，可以用 Email 自行重設。

## 持續整合

`.github/workflows/ci.yml` 會在推送到 `main`、`babygo-crm` 和開 PR 時，用 PHP 8.4 與 8.5 跑全部測試。它只負責測試，**不會自動部署**。
