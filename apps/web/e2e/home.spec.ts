import { test, expect } from '@playwright/test'

test('la portada muestra el título y el botón de comprobación', async ({ page }) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { name: 'Estado de la API' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Volver a comprobar' })).toBeVisible()
})
