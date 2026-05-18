from playwright.sync_api import sync_playwright

with sync_playwright() as p:
    browser = p.chromium.launch(headless=False)  # GUIで起動
    page = browser.new_page()
    page.goto('https://x.com/login')
    # ここで手動ログイン
    input("ログイン完了したらEnterを押してください...")
    # Cookie保存
    import json
    cookies = page.context.cookies()
    with open('x_cookies.json', 'w') as f:
        json.dump(cookies, f)
    print("Cookie保存完了")
    browser.close()
