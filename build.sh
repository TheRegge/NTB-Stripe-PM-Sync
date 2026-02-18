#!/bin/bash

# NTB Stripe PM Sync — Build Script
# Creates a distribution-ready zip file for WordPress upload

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}Building NTB Stripe PM Sync...${NC}\n"

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# Extract version from plugin header
VERSION=$(grep "^ \* Version:" ntb-stripe-pm-sync.php | awk '{print $3}')
if [ -z "$VERSION" ]; then
    echo -e "${YELLOW}Warning: Could not extract version, using 'dev'${NC}"
    VERSION="dev"
fi

echo -e "Version: ${GREEN}${VERSION}${NC}"

# Set up directories and filenames
PLUGIN_SLUG="ntb-stripe-pm-sync"
BUILD_DIR="releases"
ZIP_NAME="${PLUGIN_SLUG}-${VERSION}.zip"
ZIP_PATH="${BUILD_DIR}/${ZIP_NAME}"

# Create releases directory if it doesn't exist
mkdir -p "$BUILD_DIR"

# Remove existing zip if present
if [ -f "$ZIP_PATH" ]; then
    echo "Removing existing ${ZIP_NAME}..."
    rm "$ZIP_PATH"
fi

echo -e "\nCreating zip file..."

# Create temp directory for proper folder structure
TEMP_DIR=$(mktemp -d)
PLUGIN_DIR="${TEMP_DIR}/${PLUGIN_SLUG}"
mkdir -p "$PLUGIN_DIR"

# Copy production files only, excluding dev/spec artifacts
rsync -a \
    --exclude='.git' \
    --exclude='.gitignore' \
    --exclude='.claude' \
    --exclude='.aidoc' \
    --exclude='.semverrc' \
    --exclude='.semver-cache.json' \
    --exclude='.DS_Store' \
    --exclude='._*' \
    --exclude='.vscode' \
    --exclude='.idea' \
    --exclude='.env*' \
    --exclude='CLAUDE.md' \
    --exclude='CLAUDE-IMPLEMENTATION-PROMPT.md' \
    --exclude='STRIPE-PM-SYNC-SPEC.md' \
    --exclude='IMPLEMENTATION-PLAN.md' \
    --exclude='TESTING-LOG.md' \
    --exclude='build.sh' \
    --exclude='releases' \
    --exclude='*.zip' \
    --exclude='*.log' \
    --exclude='*.bak' \
    --exclude='*.backup' \
    --exclude='*.sql*' \
    --exclude='*.db' \
    --exclude='test.php' \
    --exclude='scratch.php' \
    --exclude='TODO.md' \
    --exclude='NOTES.md' \
    . "$PLUGIN_DIR/"

# Create zip with proper folder structure
cd "$TEMP_DIR"
zip -r "${SCRIPT_DIR}/${ZIP_PATH}" "$PLUGIN_SLUG" > /dev/null
cd "$SCRIPT_DIR"

# Clean up temp directory
rm -rf "$TEMP_DIR"

# Get file size
FILE_SIZE=$(du -h "$ZIP_PATH" | cut -f1)

echo -e "\n${GREEN}Build complete!${NC}"
echo -e "\nPlugin: ${BLUE}${PLUGIN_SLUG}${NC}"
echo -e "Version: ${BLUE}${VERSION}${NC}"
echo -e "File: ${BLUE}${ZIP_PATH}${NC}"
echo -e "Size: ${BLUE}${FILE_SIZE}${NC}"
echo -e "\n${GREEN}Ready to upload to WordPress!${NC}\n"
