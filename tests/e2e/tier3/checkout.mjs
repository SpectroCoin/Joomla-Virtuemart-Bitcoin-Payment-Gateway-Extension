/**
 * Drives a real shopper through VirtueMart's checkout in a real browser.
 *
 * Tier 2 seeds the SpectroCoin order directly on the stub and delivers
 * callbacks onto a VirtueMart order it inserted by hand - it proves the
 * callback contract but never that a shopper can reach the gateway.
 * plgVmConfirmedOrder assembles the real order from a VirtueMartCart built up
 * over several checkout steps in the shopper's session, and none of that is
 * exercised without a browser walking through it: add to cart, fill in
 * shopper details, pick a shipment method, pick a payment method, check out,
 * and land on the SpectroCoin payment page.
 *
 * Prints PASS/FAIL/INFO lines for the shell wrapper to count.
 */

import { chromium } from 'playwright';

const SHOP = process.env.SHOP_URL || 'http://shop.test';
const PRODUCT_URL = process.env.PRODUCT_URL;
const TITLE = process.env.EXT_TITLE || 'SpectroCoin';
const STOCK_TITLE = process.env.STOCK_TITLE || 'Cash on delivery';

let failed = 0;
const pass = (m) => console.log(`PASS ${m}`);
const fail = (m) => { failed++; console.log(`FAIL ${m}`); };
const info = (m) => console.log(`INFO ${m}`);

const browser = await chromium.launch();
// The stub answers as spectrocoin.com with a certificate from a CA generated
// by the harness, which the browser has no reason to trust.
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();
page.setDefaultTimeout(30000);

const shot = async (name) => {
  try { await page.screenshot({ path: `/work/artifacts/${name}.png`, fullPage: true }); } catch {}
};

const fill = async (sel, value) => {
  const el = page.locator(sel).first();
  if (await el.count()) { await el.fill(value).catch(() => {}); return true; }
  return false;
};

try {
  // ---- add to cart ------------------------------------------------------
  await page.goto(PRODUCT_URL, { waitUntil: 'domcontentloaded' });
  const addToCart = page.locator('button.addtocart-button').first();
  if (await addToCart.count()) {
    await addToCart.click();
    await page.waitForTimeout(1500);
    pass('product can be added to the cart');
  } else {
    fail('no add-to-cart button on the product page');
    await shot('product');
  }

  // ---- checkout: shopper details -----------------------------------------
  await page.goto(`${SHOP}/index.php?option=com_virtuemart&view=cart`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1000);

  const hasAddressForm = await page.locator('#first_name_field').count();
  if (hasAddressForm) {
    await fill('#email_field', 'tier3@example.com');
    await fill('#first_name_field', 'Tier');
    await fill('#last_name_field', 'Three');
    await fill('#address_1_field', '1 Test Street');
    await fill('#city_field', 'Seattle');
    await fill('#zip_field', '98101');
    // The sample vendor only ships to the United States, which VirtueMart
    // requires a state for - both are fixture facts, not something the
    // plugin has any say over.
    await page.selectOption('#virtuemart_country_id_field', '223', { force: true }).catch(() => {});
    await page.dispatchEvent('#virtuemart_country_id_field', 'change').catch(() => {});
    await page.waitForTimeout(1500);
    pass('shopper details can be entered');
  } else {
    fail('no shopper-details form on the cart page');
    await shot('checkout-start');
  }

  const checkoutSubmit = page.locator('#checkoutFormSubmit');
  if (await checkoutSubmit.count()) {
    await checkoutSubmit.click();
    await page.waitForTimeout(2000);
  } else {
    fail('no checkout submit button on the cart page');
    await shot('checkout-no-submit');
  }

  // VirtueMart bounces to its own address-edit view when a country-specific
  // required field (here: US state) was left blank, rather than accepting the
  // order without it.
  if (await page.locator('#virtuemart_state_id_field').count()) {
    await page.selectOption('#virtuemart_state_id_field', { index: 1 }, { force: true }).catch(() => {});
    const saveButton = page.locator('#userForm button[type="submit"]').first();
    if (await saveButton.count()) {
      await saveButton.click();
      await page.waitForTimeout(2000);
    }
  }

  // ---- checkout: shipment and payment -------------------------------------
  const isBlockCheckout = await page.locator('.vm-payment-shipment-select, fieldset.vm-shipment-select').count() > 0;
  info(`checkout page reached: ${isBlockCheckout ? 'shipment/payment step shown' : 'unexpected page'}`);

  // Decisive control: if the payment step is empty for every method, that is
  // a fixture problem, not evidence against our plugin. A stock VirtueMart
  // payment method has to be offered too, or "ours is missing" tells us
  // nothing.
  const stockOffered = await page.getByText(STOCK_TITLE, { exact: false }).count();
  if (stockOffered > 0) {
    pass(`a stock payment method (${STOCK_TITLE}) is offered too (control for an empty payment step)`);
  } else {
    fail(`no stock payment method is offered, not even ${STOCK_TITLE} - this is a fixture problem, not the plugin`);
    await shot('payment-no-control');
  }

  const offered = await page.getByText(TITLE, { exact: false }).count();
  if (offered > 0) {
    pass('the plugin is offered at checkout');
  } else {
    fail('the plugin is NOT offered at checkout');
    await shot('payment-no-spectrocoin');
  }

  const option = page.locator('input[name="virtuemart_paymentmethod_id"]');
  const count = await option.count();
  let selected = false;
  for (let i = 0; i < count; i++) {
    const id = await option.nth(i).getAttribute('id');
    const label = page.locator(`label[for="${id}"]`);
    if (await label.count() && (await label.innerText()).toLowerCase().includes(TITLE.toLowerCase())) {
      await option.nth(i).check({ force: true });
      selected = true;
      break;
    }
  }
  if (selected) {
    pass('the plugin can be selected');
  } else {
    fail('the plugin could not be selected');
    await shot('payment-cannot-select');
  }
  await page.waitForTimeout(1500);

  // A store-wide requirement, not anything the plugin controls - but the
  // journey does not complete without it. The link next to the checkbox opens
  // a fancybox popup that then has to be dismissed to reach the checkout
  // button underneath it, so the box is ticked directly instead.
  await page.locator('input[type="checkbox"][name="tos"]').first().evaluate((el) => {
    el.checked = true;
    el.dispatchEvent(new Event('change', { bubbles: true }));
    el.dispatchEvent(new Event('click', { bubbles: true }));
  }).catch(() => {});
  await page.waitForTimeout(500);

  // ---- check out --------------------------------------------------------
  const finalSubmit = page.locator('#checkoutFormSubmit');
  if (!(await finalSubmit.count())) {
    fail('no checkout button once the plugin is selected');
    await shot('confirm-missing');
  } else {
    await Promise.all([
      page.waitForURL(/spectrocoin\.com\/pay\//, { timeout: 45000 }).catch(() => {}),
      finalSubmit.click(),
    ]);
    await page.waitForTimeout(3000);

    const url = page.url();
    info(`landed on: ${url}`);
    if (/spectrocoin\.com\/pay\//.test(url)) {
      pass('checking out redirects the shopper to SpectroCoin');
    } else {
      fail(`checking out did not redirect to SpectroCoin (landed on ${url})`);
      await shot('after-checkout');
    }
  }
} catch (err) {
  fail(`browser run threw: ${err.message.split('\n')[0]}`);
  await shot('threw');
} finally {
  await browser.close();
}

process.exit(failed === 0 ? 0 : 1);
