const path = require('path');
const { test, expect } = require('@playwright/test');

function evidenceForm() {
  return '<section class="card stack" aria-label="Pausenlage klären"><h2>Pausenlage klären</h2>'
    + '<p>Arbeitsbuchung #42 · Gespeicherte Pause: 30 Min. · Keine konkreten Pausenintervalle dokumentiert.</p>'
    + '<form method="post" class="form-grid" data-pause-evidence-form>'
    + '<input type="hidden" name="source_token" value="current-source"><input type="hidden" name="request_id" value="explicit-request">'
    + '<div class="full-span stack" data-pause-intervals><div class="form-grid" data-pause-interval>'
    + '<label><span>Tatsächlich Pause ab</span><input type="datetime-local" name="pause_start[]" required></label>'
    + '<label><span>Tatsächlich Pause bis</span><input type="datetime-local" name="pause_end[]" required></label>'
    + '<button class="button button-secondary" type="button" data-pause-remove hidden>Intervall entfernen</button></div></div>'
    + '<button class="button button-secondary" type="button" data-pause-add>Weiteres tatsächliches Intervall</button>'
    + '<label class="full-span"><span>Pflichtbegründung und Herkunft des Nachweises</span><textarea name="reason" minlength="5" required></textarea></label>'
    + '<label class="full-span"><span><input type="checkbox" name="actual_break_attestation" value="1" required> Ich bestätige, dass diese Pausen tatsächlich genommen wurden.</span></label>'
    + '<button class="button" type="submit">Tatsächliche Pausen dokumentieren</button></form></section>';
}

test('pause evidence starts empty and every added interval needs an actual explicit time', async ({ page }) => {
  await page.setContent(evidenceForm());
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-work-time.js') });
  await expect(page.locator('[name="pause_start[]"]')).toHaveValue('');
  await expect(page.locator('[name="pause_end[]"]')).toHaveValue('');
  await page.locator('[name="pause_start[]"]').fill('2026-11-10T12:00');
  await page.locator('[name="pause_end[]"]').fill('2026-11-10T12:15');
  await page.getByRole('button', { name: 'Weiteres tatsächliches Intervall', exact: true }).click();
  const rows = page.locator('[data-pause-interval]');
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(1).locator('[name="pause_start[]"]')).toHaveValue('');
  await expect(rows.nth(1).locator('[name="pause_end[]"]')).toHaveValue('');
  await rows.nth(1).getByRole('button', { name: 'Intervall entfernen' }).click();
  await expect(rows).toHaveCount(1);
  await expect(rows.first().locator('[name="pause_start[]"]')).toHaveValue('2026-11-10T12:00');
});

test('pause documentation requires actual evidence, reason and attestation before one submission', async ({ page }) => {
  await page.setContent(evidenceForm());
  await page.addScriptTag({ path: path.join(__dirname, '../../public/assets/js/admin-work-time.js') });
  await page.evaluate(() => {
    window.submissions = [];
    document.querySelector('form').addEventListener('submit', (event) => {
      event.preventDefault();
      window.submissions.push(Object.fromEntries(new FormData(event.target)));
    });
  });
  const save = page.getByRole('button', { name: 'Tatsächliche Pausen dokumentieren', exact: true });
  await save.click();
  expect(await page.evaluate(() => window.submissions.length)).toBe(0);
  await page.locator('[name="pause_start[]"]').fill('2026-11-10T12:00');
  await page.locator('[name="pause_end[]"]').fill('2026-11-10T12:30');
  await page.locator('[name="reason"]').fill('Mitarbeiter hat tatsächliche Mittagspause bestätigt.');
  await save.click();
  expect(await page.evaluate(() => window.submissions.length)).toBe(0);
  await page.locator('[name="actual_break_attestation"]').check();
  await save.click();
  await expect(page.getByRole('button', { name: 'Nachweis wird gespeichert …' })).toBeDisabled();
  expect(await page.evaluate(() => window.submissions)).toEqual([expect.objectContaining({
    'pause_start[]': '2026-11-10T12:00', 'pause_end[]': '2026-11-10T12:30', actual_break_attestation: '1', source_token: 'current-source'
  })]);
});
