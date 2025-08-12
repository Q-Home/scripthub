#!/usr/bin/env python3

file_path = "/opt/loxberry/data/plugins/scripthub/output.txt"

with open(file_path, "w") as f:
    f.write("Hello, this is a test file.\n")