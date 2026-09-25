import socket
import time
for _ in range(40):
    try:
        with socket.create_connection(('127.0.0.1', 9081), timeout=0.5):
            break
    except OSError:
        time.sleep(0.25)
else:
    raise SystemExit('Agent did not become ready within 10 seconds')
