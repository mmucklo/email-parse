#!/bin/bash
# Local CI Testing Helper Script

echo "GitHub Actions Local Testing"
echo "============================="
echo ""
echo "Available commands:"
echo "  1. Test PHP 7.1:  act -j tests --matrix php-version:7.1"
echo "  2. Test PHP 8.1:  act -j tests --matrix php-version:8.1"
echo "  3. Test PHP 8.2:  act -j tests --matrix php-version:8.2"
echo "  4. Code Style:    act -j code-style"
echo "  5. PHPStan:       act -j static-analysis"
echo "  6. Coverage:      act -j coverage"
echo "  7. All PHP:       act -j tests"
echo "  8. Full CI:       act push"
echo ""

if ! command -v act &> /dev/null; then
    echo "ERROR: 'act' is not installed!"
    echo ""
    echo "Install with:"
    echo "  macOS:  brew install act"
    echo "  Linux:  curl https://raw.githubusercontent.com/nektos/act/master/install.sh | sudo bash"
    exit 1
fi

echo "Select test (1-8) or press Enter to run full CI:"
read -r choice

case $choice in
    1) act -j tests --matrix php-version:7.1 ;;
    2) act -j tests --matrix php-version:8.1 ;;
    3) act -j tests --matrix php-version:8.2 ;;
    4) act -j code-style ;;
    5) act -j static-analysis ;;
    6) act -j coverage ;;
    7) act -j tests ;;
    8|"") act push ;;
    *) echo "Invalid choice"; exit 1 ;;
esac
