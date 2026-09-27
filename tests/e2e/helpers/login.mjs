/**
 * Playwright helper: log into wp-admin as the seeded admin user (see
 * tests/docker/wp/entrypoint.sh, which creates it via `wp core install`).
 */

export async function loginAsAdmin(page, baseURL) {
    const user = process.env.TRACKWP_WP_ADMIN_USER || 'admin';
    const pass = process.env.TRACKWP_WP_ADMIN_PASSWORD || 'admin';

    await page.goto(new URL('/wp-login.php', baseURL).toString());
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await page.click('#wp-submit');
    await page.waitForURL(/wp-admin/);
}

export async function logout(page, baseURL) {
    await page.goto(new URL('/wp-login.php?action=logout', baseURL).toString());
    const confirmLink = page.locator('a', { hasText: /log out/i });
    if (await confirmLink.count()) {
        await confirmLink.click();
    }
}
