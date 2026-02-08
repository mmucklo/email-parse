#!/usr/bin/env python3
import re

with open('tests/testspec.yml', 'r') as f:
    content = f.read()

# Add comments: [] after invalid_reason in email_addresses arrays
# Pattern: invalid_reason followed by a line that starts with - or indented address:
content = re.sub(
    r"(^                invalid_reason: .*$)(\n(?=                [-a]|\n            -))",
    r"\1\n                comments: []\2",
    content,
    flags=re.MULTILINE
)

with open('tests/testspec.yml', 'w') as f:
    f.write(content)

print("Added comments field to email_addresses arrays")
