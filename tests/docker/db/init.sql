-- Extra databases for the test matrix. MYSQL_DATABASE (compose.yml) creates
-- the first one; this script creates the rest on first container start.
CREATE DATABASE IF NOT EXISTS wordpress_test;
CREATE DATABASE IF NOT EXISTS wordpress_test_legacy;
CREATE DATABASE IF NOT EXISTS wordpress71;
CREATE DATABASE IF NOT EXISTS wordpress62;

-- Single shared root user for all test services; this is throwaway
-- infrastructure torn down with `docker compose down -v`, never a real site.
GRANT ALL PRIVILEGES ON *.* TO 'root'@'%';
FLUSH PRIVILEGES;
