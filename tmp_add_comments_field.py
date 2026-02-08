import yaml
import sys

# Read the testspec file
with open('tests/testspec.yml', 'r') as f:
    tests = yaml.safe_load(f)

# Add comments field to all tests that don't have it
for test in tests:
    if 'result' in test:
        if 'email_addresses' in test['result']:
            # Multiple emails
            for email in test['result']['email_addresses']:
                if 'comments' not in email:
                    email['comments'] = []
        else:
            # Single email
            if 'comments' not in test['result']:
                test['result']['comments'] = []

# Write back
with open('tests/testspec.yml', 'w') as f:
    yaml.dump(tests, f, default_flow_style=False, allow_unicode=True, sort_keys=False)

print("Added comments field to all tests")
