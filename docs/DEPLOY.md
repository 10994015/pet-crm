# 部署指南

給負責架設與更新 BabyGo 正式網站的人。假設是一台 Linux 主機（Nginx + PHP-FPM），網站放在 `/var/www/babygo`。

## 需要準備

- PHP 8.4 以上，並啟用 `mbstring`、`intl`、`zip`、`xml`、`fileinfo`、`pdo_sqlite`（用 MySQL 時改成 `pdo_mysql`）
- Composer 2
- Nginx 或 Apache，以及 HTTPS 憑證（例如 Let's Encrypt）
- SQLite，或 MySQL／MariaDB

不需要 Node.js：前台與後台的樣式都已經放在 `public/`，沒有前端建置步驟。

目前也不需要 cron 或佇列 worker，因為系統還沒有排程和背景工作。

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

建議每次更新前先備份。

## 備份（尚未自動化）

**系統目前沒有備份機制，請在開放使用前自行設定。** 要備份的有：

- **資料庫：** SQLite 的 `database.sqlite`，或 MySQL 的 dump
- **上傳的照片：** `storage/app/public/`
- **設定檔：** `.env`，尤其 `APP_KEY`

備份要存到另一台主機或雲端，並實際還原過一次，確認可用。

## 持續整合

`.github/workflows/ci.yml` 會在推送到 `main`、`babygo-crm` 和開 PR 時，用 PHP 8.4 與 8.5 跑全部測試。它只負責測試，**不會自動部署**。
