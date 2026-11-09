"""
FEMECI Cloudflare Tunnel Launcher
- Inicia cloudflared tunnel hacia localhost:8766
- Captura la URL generada
- Actualiza STT_URL en Railway automaticamente
"""
import subprocess
import re
import time
import os
import sys

CLOUDFLARED = r"C:\Users\abelM\bin\cloudflared.exe"
RAILWAY_CMD = "railway.cmd"
PROJECT_DIR = r"C:\xampp\htdocs\ExpedienteFEMECI"
PORT = 8766

def main():
    # Start cloudflared
    proc = subprocess.Popen(
        [CLOUDFLARED, "tunnel", "--url", f"http://localhost:{PORT}", "--protocol", "http2", "--no-autoupdate"],
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        encoding="utf-8",
        errors="replace"
    )

    tunnel_url = None
    # Read output line by line looking for the URL
    for line in proc.stdout:
        line = line.strip()
        match = re.search(r'https://[a-z0-9-]+\.trycloudflare\.com', line)
        if match:
            tunnel_url = match.group(0)
            break

    if tunnel_url:
        # Write URL to file for reference
        url_file = os.path.join(PROJECT_DIR, "app", "servicios", "ia", "tunnel_url.txt")
        with open(url_file, 'w') as f:
            f.write(tunnel_url)

        # Update Railway variable
        try:
            result = subprocess.run(
                [RAILWAY_CMD, "variables", "set", f"STT_URL={tunnel_url}"],
                cwd=PROJECT_DIR,
                capture_output=True, text=True, timeout=30
            )
            if result.returncode == 0:
                log(f"Railway STT_URL updated to {tunnel_url}")
            else:
                log(f"Railway update failed: {result.stderr}")
        except Exception as e:
            log(f"Railway update error: {e}")

        log(f"Tunnel running at {tunnel_url}")
    else:
        log("ERROR: Could not capture tunnel URL")

    # Keep running (cloudflared stays alive)
    try:
        proc.wait()
    except KeyboardInterrupt:
        proc.terminate()

def log(msg):
    log_file = os.path.join(PROJECT_DIR, "app", "servicios", "ia", "tunnel.log")
    timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
    entry = f"[{timestamp}] {msg}\n"
    with open(log_file, 'a') as f:
        f.write(entry)
    print(entry, end='')

if __name__ == "__main__":
    main()
