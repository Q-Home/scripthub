#!/usr/bin/env python3
import time

file_path = "/opt/loxberry/data/plugins/scripthub/scripthub_cron.log"

for i in range(10):
    with open(file_path, "a") as f:
        f.write(f"[test.py] Hello, this is a test. Iteration {i}\n")
    time.sleep(5)  # stay alive for ~50 seconds