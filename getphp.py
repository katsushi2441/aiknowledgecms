#!/usr/bin/env python3
from ftplib import FTP
import os


FTP_HOST = "ftp-exbridge.heteml.net"
FTP_USER = "exbridge"
FTP_PASS = "Xbrg20042025"

PHP_FILES = ["osszenn.php","oss.php","xinsightv.php","ustoryv.php"]

def ftp_items_php_download():
    ftp = FTP()
    ftp.encoding = "utf-8"
    ftp.connect(FTP_HOST, 21, timeout=10)
    ftp.login(FTP_USER, FTP_PASS)
    ftp.cwd("web")
    ftp.cwd("aiknowledgecms_exbridge_jp")

    remote_files = ftp.nlst()

    for php in PHP_FILES:
        if php not in remote_files:
            print(f"⚠️  {php} はサーバーに存在しません。スキップ")
            continue
        print(f"⬇️  Download: {php}")
        with open(php, "wb") as f:
            ftp.retrbinary("RETR " + php, f.write)
        print(f"✅ {php} ダウンロード成功")

    ftp.quit()
    print("🎉 全ファイルダウンロード完了")

if __name__ == "__main__":
    ftp_items_php_download()
