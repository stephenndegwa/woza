#!/bin/bash

# WHMCS Woza M-pesa Gateway - Installation Script
# This script helps install the gateway files to the correct locations

echo "🚀 WHMCS Woza M-pesa Gateway - Installation Script"
echo "================================================="
echo ""

# Check if we're in WHMCS root directory
if [[ ! -f "configuration.php" ]] && [[ ! -f "init.php" ]]; then
    echo "❌ Error: Please run this script from your WHMCS root directory"
    echo "   Example: cd /var/www/html/whmcs && bash install.sh"
    exit 1
fi

echo "✅ WHMCS directory detected"

# Create directories if they don't exist
mkdir -p modules/gateways/woza/callback
mkdir -p woza

# Copy files
echo ""
echo "📁 Installing gateway files..."

# Main gateway file
cp modules/gateways/woza.php modules/gateways/ 2>/dev/null && echo "✅ Installed: modules/gateways/woza.php" || echo "⚠️  Could not install woza.php"

# Callback handler
cp modules/gateways/woza/callback/woza.php modules/gateways/woza/callback/ 2>/dev/null && echo "✅ Installed: modules/gateways/woza/callback/woza.php" || echo "⚠️  Could not install callback handler"

# Woza directory files
cp woza/confirmation.php woza/ 2>/dev/null && echo "✅ Installed: woza/confirmation.php" || echo "⚠️  Could not install confirmation.php"
cp woza/validation.php woza/ 2>/dev/null && echo "✅ Installed: woza/validation.php" || echo "⚠️  Could not install validation.php"
cp woza/register_urls.php woza/ 2>/dev/null && echo "✅ Installed: woza/register_urls.php" || echo "⚠️  Could not install register_urls.php"

# Main processing files
cp payment.php . 2>/dev/null && echo "✅ Installed: payment.php" || echo "⚠️  Could not install payment.php"
cp stkpush.php . 2>/dev/null && echo "✅ Installed: stkpush.php" || echo "⚠️  Could not install stkpush.php"
cp check-status.php . 2>/dev/null && echo "✅ Installed: check-status.php" || echo "⚠️  Could not install check-status.php"

# Set permissions
echo ""
echo "🔒 Setting file permissions..."
chmod 644 modules/gateways/woza.php
chmod 644 modules/gateways/woza/callback/woza.php
chmod 644 woza/confirmation.php woza/validation.php woza/register_urls.php
chmod 644 payment.php stkpush.php check-status.php

echo ""
echo "🎉 Installation completed!"
echo ""
echo "📋 Next steps:"
echo "1. Login to WHMCS Admin Panel"
echo "2. Go to Setup → Payments → Payment Gateways"
echo "3. Find 'Woza' and click 'Activate'"
echo "4. Configure your M-pesa API credentials"
echo "5. Set callback URL: https://yourdomain.com/modules/gateways/woza/callback/woza.php"
echo "6. Test with a small transaction"
echo ""
echo "📚 Documentation: See documentation/ folder for detailed guides"
echo "🆘 Support: Check README.md for support information"
