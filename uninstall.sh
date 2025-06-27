#!/bin/bash

# WHMCS Woza M-pesa Gateway - Uninstall Script

echo "🗑️  WHMCS Woza M-pesa Gateway - Uninstall Script"
echo "==============================================="
echo ""

read -p "Are you sure you want to uninstall Woza M-pesa Gateway? (y/N): " -n 1 -r
echo ""

if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo "Removing gateway files..."
    
    # Remove main files
    rm -f modules/gateways/woza.php && echo "✅ Removed: modules/gateways/woza.php"
    rm -rf modules/gateways/woza/ && echo "✅ Removed: modules/gateways/woza/ directory"
    rm -rf woza/ && echo "✅ Removed: woza/ directory"
    rm -f payment.php && echo "✅ Removed: payment.php"
    rm -f stkpush.php && echo "✅ Removed: stkpush.php"
    rm -f check-status.php && echo "✅ Removed: check-status.php"
    
    echo ""
    echo "⚠️  Note: Database tables and transaction data have been preserved"
    echo "   If you want to remove all data, please do so manually from your database"
    echo ""
    echo "🎉 Uninstall completed!"
else
    echo "Uninstall cancelled."
fi
