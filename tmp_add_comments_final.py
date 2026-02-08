#!/usr/bin/env python3

with open('tests/testspec.yml', 'r') as f:
    lines = f.readlines()

output = []
i = 0
while i < len(lines):
    line = lines[i]
    output.append(line)
    
    # Check if this line has invalid_reason at any indentation
    if 'invalid_reason:' in line and line.strip().startswith('invalid_reason:'):
        # Determine indentation level
        indent = len(line) - len(line.lstrip())
        # Add comments: [] at the same indentation
        output.append(' ' * indent + 'comments: []\n')
    
    i += 1

with open('tests/testspec.yml', 'w') as f:
    f.writelines(output)

print("Added comments field to all tests")
