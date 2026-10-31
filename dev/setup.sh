#!/usr/bin/env bash
# One-shot local dev environment: portable PHP + WordPress (SQLite, no MySQL) + WooCommerce.
# No XAMPP, no Docker, no admin rights. Re-runnable.
set -euo pipefail

DEV="$(cd "$(dirname "$0")" && pwd)"
RT="$DEV/runtime"
DL="$RT/downloads"
PHP_DIR="$RT/php"
WP_DIR="$RT/wordpress"
PORT="${PORT:-8080}"
SITE_URL="http://localhost:$PORT"

mkdir -p "$DL"
cd "$DL"
fetch() { [ -s "$1" ] || curl -sSL --retry 3 -o "$1" "$2"; }
fetch php.zip        https://downloads.php.net/~windows/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip
fetch wordpress.zip  https://wordpress.org/latest.zip
fetch woocommerce.zip https://downloads.wordpress.org/plugin/woocommerce.zip
fetch sqlite.zip     https://downloads.wordpress.org/plugin/sqlite-database-integration.zip
fetch wp-cli.phar    https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

# --- PHP ---------------------------------------------------------------
if [ ! -f "$PHP_DIR/php.exe" ]; then
  mkdir -p "$PHP_DIR" && unzip -q -o php.zip -d "$PHP_DIR"
fi
EXT_DIR="$(cygpath -m "$PHP_DIR/ext")"
sed -e "s#^;\?extension_dir = \"ext\"#extension_dir = \"$EXT_DIR\"#" \
    -e 's/^;extension=\(curl\|fileinfo\|gd\|intl\|mbstring\|exif\|openssl\|pdo_sqlite\|sqlite3\|zip\|sodium\)$/extension=\1/' \
    -e 's/^memory_limit = .*/memory_limit = 512M/' \
    -e 's/^max_execution_time = .*/max_execution_time = 300/' \
    -e 's/^post_max_size = .*/post_max_size = 64M/' \
    -e 's/^upload_max_filesize = .*/upload_max_filesize = 64M/' \
    -e 's/^;date.timezone =.*/date.timezone = UTC/' \
    "$PHP_DIR/php.ini-development" > "$PHP_DIR/php.ini"
PHP="$PHP_DIR/php.exe"
"$PHP" -v | head -1
"$PHP" -m | grep -iE '^(curl|openssl|pdo_sqlite|sqlite3|mbstring|intl|gd|zip)$' | tr '\n' ' '; echo

# --- WordPress + SQLite + WooCommerce ---------------------------------------
if [ ! -f "$WP_DIR/wp-settings.php" ]; then
  unzip -q -o wordpress.zip -d "$RT"
fi
[ -d "$WP_DIR/wp-content/plugins/sqlite-database-integration" ] || unzip -q -o sqlite.zip -d "$WP_DIR/wp-content/plugins"
[ -d "$WP_DIR/wp-content/plugins/woocommerce" ] || unzip -q -o woocommerce.zip -d "$WP_DIR/wp-content/plugins"
cp "$WP_DIR/wp-content/plugins/sqlite-database-integration/db.copy" "$WP_DIR/wp-content/db.php"

if [ ! -f "$WP_DIR/wp-config.php" ]; then
  salt() { "$PHP" -r 'echo bin2hex(random_bytes(32));'; }
  cat > "$WP_DIR/wp-config.php" <<EOF
<?php
define( 'DB_NAME', 'wordpress' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' );
define( 'DB_FILE', '.ht.sqlite' );

define( 'AUTH_KEY',         '$(salt)' );
define( 'SECURE_AUTH_KEY',  '$(salt)' );
define( 'LOGGED_IN_KEY',    '$(salt)' );
define( 'NONCE_KEY',        '$(salt)' );
define( 'AUTH_SALT',        '$(salt)' );
define( 'SECURE_AUTH_SALT', '$(salt)' );
define( 'LOGGED_IN_SALT',   '$(salt)' );
define( 'NONCE_SALT',       '$(salt)' );

\$table_prefix = 'wp_';

define( 'WP_HOME', '$SITE_URL' );
define( 'WP_SITEURL', '$SITE_URL' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
EOF
fi

WP() { "$PHP" "$DL/wp-cli.phar" --path="$WP_DIR" "$@"; }
if ! WP core is-installed 2>/dev/null; then
  WP core install --url="$SITE_URL" --title="ShopEase" --admin_user=admin --admin_password=admin \
     --admin_email=admin@shopease.test --skip-email
fi
WP plugin activate sqlite-database-integration woocommerce

# Link the plugin source into WordPress and install the tunnel guard (public tunnel exposes only VoicePress endpoints).
if [ ! -e "$WP_DIR/wp-content/plugins/voicepress" ]; then
  powershell.exe -NoProfile -Command "New-Item -ItemType Junction -Path '$(cygpath -w "$WP_DIR/wp-content/plugins/voicepress")' -Target '$(cygpath -w "$DEV/../plugin/voicepress")'" >/dev/null
fi
mkdir -p "$WP_DIR/wp-content/mu-plugins"
cp "$DEV/tunnel-guard.php" "$WP_DIR/wp-content/mu-plugins/voicepress-tunnel-guard.php"
cp "$DEV/mail-sink.php" "$WP_DIR/wp-content/mu-plugins/dev-mail-sink.php"
WP plugin activate voicepress || true
echo "SETUP_OK  ->  $SITE_URL/wp-admin  (admin / admin)"
