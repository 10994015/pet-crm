# BabyGo 寵物美容 CRM 與預約網站

給寵物美容店用的系統：店家在後台管理客人、毛孩、預約和營收，飼主在網站上線上預約、查詢紀錄。

## 功能

**店家後台（`/admin`）**

- 客戶與毛孩資料、美容前後照片
- 預約管理、每日行程表、美容師時段衝突檢查
- 特定日期店休與美容師請假
- 每日自動備份，也可以在後台手動備份和下載
- 包月方案、商品與訂單
- 營收報表、回訪提醒、Excel 匯出
- 業主與員工兩種權限

**公開網站**

- 線上預約：選服務、挑時段、填資料，店家確認後完成
- 用手機號碼加預約代碼查詢預約
- 會員中心：毛孩、消費紀錄、包月方案、取消預約
- 隱私權聲明與同意紀錄

## 技術

Laravel 13、Filament 5、Livewire。需要 **PHP 8.4 以上**（`composer.lock` 裡的套件有此要求）和 Composer 2。預設使用 SQLite，也支援 MySQL／MariaDB。

不需要 Node.js：樣式直接放在 `public/css/`，沒有前端建置步驟。

## 本機開發

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --force      # SQLite 資料庫檔案會自動建立
php artisan db:seed              # 建立業主帳號與入門價目表，密碼會顯示在畫面上
php artisan storage:link
php artisan serve --port=8020
```

- 網站：<http://127.0.0.1:8020>
- 後台：<http://127.0.0.1:8020/admin>

業主帳號預設是 `owner`。沒有設定 `OWNER_PASSWORD` 時，`db:seed` 會產生隨機密碼，只顯示這一次，請先記下來。

### 示範資料

```bash
php artisan db:seed --class=DemoSeeder       # 少量示範客戶與預約
php artisan db:seed --class=FakeDataSeeder   # 100 個家庭與一整年的紀錄，適合看報表
```

這些都是假資料，不要在正式環境執行。

## 從舊系統匯入資料

把舊系統（bibidog）的客戶、毛孩、服務、訂單和銷售紀錄複製過來。先在 `.env` 填好 `BIBIDOG_DB_*`：

```bash
php artisan bibidog:import --dry-run   # 試跑：顯示筆數與金額核對，不會寫入
php artisan bibidog:import             # 金額核對相符後才正式匯入
```

如果系統裡已經有非匯入的客戶，指令會停下來，避免新舊資料混在一起。確定要合併才加上 `--allow-existing`。

## 測試

```bash
php artisan test
```

測試使用記憶體資料庫，不會動到開發資料。推送後 GitHub Actions 也會自動跑一次（見 `.github/workflows/ci.yml`）。

## 部署

見 [docs/DEPLOY.md](docs/DEPLOY.md)，正式環境的設定範本是 [.env.production.example](.env.production.example)。

自動備份要設定 cron 才會執行，備份檔預設也和網站在同一台主機，上線前請先看過部署指南的備份段落。系統目前不會自動寄送通知。

常用維護指令：

```bash
php artisan babygo:backup                    # 立刻備份
php artisan babygo:reset-password owner      # 忘記密碼時，產生新密碼
```

## 專案結構

| 位置 | 內容 |
| --- | --- |
| `app/Filament/` | 後台的頁面、資源與小工具 |
| `app/Livewire/BookingWizard.php` | 線上預約的三個步驟 |
| `app/Services/OnlineBooking.php` | 預約規則：可預約時段、容量、防止超收 |
| `app/Services/BibidogImporter.php` | 舊系統資料匯入 |
| `app/Services/Backup.php` | 備份資料庫與照片 |
| `app/Http/Controllers/` | 公開網站與會員中心 |
| `resources/views/front/` | 公開網站的畫面 |
| `resources/views/errors/` | 錯誤頁，刻意不依賴資料庫 |
| `public/css/` | `front.css` 給網站，`bibidog.css` 給後台 |
