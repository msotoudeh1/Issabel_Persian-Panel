**بزودی اپدیت خواهد شد **

درود . جهت نصب برنامه به راهنمای زیر توجه فرمائید :</br>
1- تمامی محتویات پوشه html را در پوشه html ایزابل خود منتقل کنید</br>
2- فایل live_monitor.php  را با برنامه notepad باز کرده و تغییرات زیر را در ان اعمال کنید :</br>
در خط 113 به حای عبارت database_password ، رمز عبورد دیتابیس ایزابل خود را قرار دهید</br>
در خط 442 نیز به جای عبارت database_password ، رمز عبور دیتابیس خود را قرار دهید</br>
در خط 232 و به جای عبارت login_password ، یک رمز عبور قوی جهت ورود به پنل و صفحه مذکور قرار دهید</br>


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

یه جای database_password رمز عبور دیتابیس خود را قرار دهید :


4- ایجاد دیتابیس لیست سیاه :


mysql -u root -p asterisk -e "

CREATE TABLE IF NOT EXISTS \`blacklist\` (
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

مرحله ۲: تنظیم Cron Job در سرور</br>
حالا باید به سیستم بگوییم هر ۱ دقیقه این اسکریپت را چک کند. دستور زیر را در SSH وارد کنید</br>
sudo crontab -e
و در انتهای فایل باز شده، این خط را اضافه کرده  و با زدن Esc  و تایپ :wq فایل را ذخیره و خارج شوید </br>

* * * * * /usr/local/bin/apply_network.sh > /dev/null 2>&1



جهت دسترسی به پنل آدرس زیر را در مرورگر وارد کنید : 

https://<you_server_ip>:/record/live_monitor.php



<img width="1645" height="741" alt="image" src="https://github.com/user-attachments/assets/c3703d34-7c3b-4af8-bd27-0e5fb41c58eb" />


<img width="329" height="590" alt="image" src="https://github.com/user-attachments/assets/4c02b619-fffa-4474-abc5-941dd01bfcbd" />

<img width="1640" height="696" alt="image" src="https://github.com/user-attachments/assets/2e4b763e-b558-479f-9c9f-73814a1535c8" />

<img width="1650" height="742" alt="image" src="https://github.com/user-attachments/assets/454579d9-140f-4f63-b5a3-1e4db48d6dee" />

<img width="1645" height="696" alt="image" src="https://github.com/user-attachments/assets/1e6ab820-231d-480b-acfe-c768298ba696" />

<img width="1637" height="933" alt="image" src="https://github.com/user-attachments/assets/c5be7b4b-4b27-414e-b647-1de34f033493" />

<img width="489" height="586" alt="image" src="https://github.com/user-attachments/assets/c1e0ae8a-4b71-4bcd-bea6-31b8152af547" />

<img width="384" height="347" alt="image" src="https://github.com/user-attachments/assets/125d3d01-48e2-4975-9c42-e679a76a80d2" />

<img width="1003" height="720" alt="image" src="https://github.com/user-attachments/assets/3d502e1f-4160-44da-9211-73d5ebde6dcd" />

<img width="1635" height="847" alt="image" src="https://github.com/user-attachments/assets/9e900fca-73d6-4b7e-a5f3-c7dadb341310" />

<img width="1643" height="471" alt="image" src="https://github.com/user-attachments/assets/bbc72c87-3413-4622-9d91-a947183b7d3f" />

<img width="427" height="299" alt="image" src="https://github.com/user-attachments/assets/b3b235d3-7e22-4443-a1df-969cb366a6b8" />





