import { test, expect } from "@playwright/test";

test.describe("WP Admin", () => {

  test("Can access the admin dashboard", async ({ page }) => {
    await page.goto("/wp-admin/index.php");
    await expect(page.locator('.wrap h1')).toContainText('Dashboard');
  });

  test("Has required admin UI", async ({ page }) => {
    await page.goto("/wp-admin/edit.php?post_type=acfe-event");

    // Year filter dropdown
    await expect(page.locator('select[name="year"]')).toBeVisible();

    // Custom admin columns
    await expect(page.locator('th#acfe\\:location')).toBeVisible();
    await expect(page.locator('th#acfe\\:dates')).toBeVisible();

    // Recurrences submenu
    await expect(
      page.locator('a[href="edit.php?post_type=acfe-recurrence"]')
    ).toBeVisible();
  })

});
