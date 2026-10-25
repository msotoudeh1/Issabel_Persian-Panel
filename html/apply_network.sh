#!/bin/bash

LOG_FILE="/var/log/apply_network.log"
echo "--- $(date) ---" >> $LOG_FILE
TRIGGER_FILE="/tmp/pbx_network_change.txt"

if [ -f "$TRIGGER_FILE" ]; then
    echo "Trigger file found. Applying settings..." >> $LOG_FILE
    source "$TRIGGER_FILE"
    
    # ۱. پیدا کردن نام دقیق کانکشن فعال در NetworkManager
    CON_NAME=$(nmcli -g GENERAL.CONNECTION device show "$IFACE" 2>/dev/null)
    
    # اگر کانکشنی پیدا نشد، از نام اینترفیس استفاده می‌کنیم
    if [ -z "$CON_NAME" ] || [ "$CON_NAME" == "--" ]; then
        CON_NAME="$IFACE"
        nmcli connection add type ethernet ifname "$IFACE" con-name "$CON_NAME" > /dev/null 2>&1
    fi
    
    echo "Using connection name: $CON_NAME" >> $LOG_FILE

    # ۲. اعمال تنظیمات از طریق nmcli (تا NetworkManager آن را بپذیرد)
    nmcli connection modify "$CON_NAME" ipv4.addresses "$IP/$PREFIX" >> $LOG_FILE 2>&1
    
    if [ ! -z "$GATEWAY" ]; then
        nmcli connection modify "$CON_NAME" ipv4.gateway "$GATEWAY" >> $LOG_FILE 2>&1
    fi
    
    if [ ! -z "$DNS1" ]; then
        nmcli connection modify "$CON_NAME" ipv4.dns "$DNS1 $DNS2" >> $LOG_FILE 2>&1
    fi
    
    nmcli connection modify "$CON_NAME" ipv4.method manual >> $LOG_FILE 2>&1
    nmcli connection up "$CON_NAME" >> $LOG_FILE 2>&1
    
    # ۳. نوشتن دستی DNS در resolv.conf برای اطمینان (چون NetworkManager گاهی آن را نادیده می‌گیرد)
    if [ ! -z "$DNS1" ]; then
        echo "nameserver $DNS1" > /etc/resolv.conf
        if [ ! -z "$DNS2" ]; then
            echo "nameserver $DNS2" >> /etc/resolv.conf
        fi
    fi
    
    # ۴. تغییر هاست‌نیم
    if [ ! -z "$HOSTNAME" ]; then
        hostnamectl set-hostname "$HOSTNAME" >> $LOG_FILE 2>&1
    fi

    # ۵. حذف فایل تریگر
    rm -f "$TRIGGER_FILE"
    echo "Settings successfully applied via nmcli." >> $LOG_FILE
else
    echo "No trigger file." >> $LOG_FILE
fi