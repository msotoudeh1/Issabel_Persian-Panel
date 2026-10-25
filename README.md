درود . جهت نصب برنامه به راهنمای زیر توجه فرمائید :
1- تمامی محتویات پوشه html را در پوشه html ایزابل خود منتقل کنید
2- فایل live_monitor.php  را با برنامه notepad باز کرده و تغییرات زیر را در ان اعمال کنید :
در خط 113 به حای عبارت database_password ، رمز عبورد دیتابیس ایزابل خود را قرار دهید
در خط 442 نیز به جای عبارت database_password ، رمز عبور دیتابیس خود را قرار دهید
در خط 232 و به جای عبارت login_password ، یک رمز عبور قوی جهت ورود به پنل و صفحه مذکور قرار دهید

3- دیتابیس های زیر را در ایزابل تعریف کنید :
mysql -u root -pdatabase_password asteriskcdrdb

CREATE TABLE IF NOT EXISTS cdr_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    uniqueid VARCHAR(64) NOT NULL,
    rating_behavior TINYINT DEFAULT 0,
    rating_solution TINYINT DEFAULT 0,
    rating_speed TINYINT DEFAULT 0,
    rating_overall TINYINT DEFAULT 0,
    rating_avg DECIMAL(3,2) DEFAULT 0,
    note TEXT,
    username VARCHAR(64) DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL,
    UNIQUE KEY uk_uid (uniqueid),
    INDEX idx_created (created_at),
    INDEX idx_avg (rating_avg)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

یه جای database_password رمز عبور دیتابیس خود را قرار دهید 

4- ایجاد دیتابیس لیست سیاه :

mysql -u root -p asterisk -e "CREATE TABLE IF NOT EXISTS \`blacklist\` (
  \`number\` VARCHAR(20) NOT NULL,
  \`description\` VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (\`number\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"

5- ایجاد دیتابیس تمای از دست رفته:

mysql -u root -p asterisk -e "
CREATE TABLE IF NOT EXISTS \`pbx_missed_followups\` (
  \`id\` INT AUTO_INCREMENT PRIMARY KEY,
  \`number\` VARCHAR(20) NOT NULL,
  \`calldate\` DATETIME NOT NULL,
  \`status\` VARCHAR(20) DEFAULT 'pending',
  \`admin_user\` VARCHAR(50) DEFAULT NULL,
  \`updated_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY \`uniq_num_date\` (\`number\`, \`calldate\`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



6-فایل apply_network.sh را به پوشه usr/local/bin منتقل کرده و در کامند دسترسی های زیر را به ان اعمال کنید :
sudo chmod +x /usr/local/bin/apply_network.sh
sudo /usr/local/bin/apply_network.sh

مرحله ۲: تنظیم Cron Job در سرور
حالا باید به سیستم بگوییم هر ۱ دقیقه این اسکریپت را چک کند. دستور زیر را در SSH وارد کنید
sudo crontab -e
و در انتهای فایل باز شده، این خط را اضافه کنید:
* * * * * /usr/local/bin/apply_network.sh > /dev/null 2>&1
و با زدن Esc کیبرود و تایپ :wq فایل را ذخیره و خارج شوید


