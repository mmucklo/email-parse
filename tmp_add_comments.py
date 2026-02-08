#!/usr/bin/env python3
import re

with open('tests/testspec.yml', 'r') as f:
    content = f.read()

# Add comments: [] right after invalid_reason lines
# This handles both single emails and multiple emails
content = re.sub(
    r"(^        invalid_reason: .*$)",
    r"\1\n        comments: []",
    content,
    flags=re.MULTILINE
)

with open('tests/testspec.yml', 'w') as f:
    f.write(content)

print("Added comments field")
