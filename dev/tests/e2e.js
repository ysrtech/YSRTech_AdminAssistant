/**
 * The panel in a real browser on the staging install, asking real questions.
 * Needs ./dev/staging/setup.sh (which links the scripted stand-in for the AI
 * provider: nothing is sent to any provider, and the answers are written by
 * a script from the tools' real results).
 *
 *   node dev/tests/e2e.js
 *
 * Env: BASE (default http://127.0.0.1:8080/index.php), OPENMAGE_CORE_DIR,
 * SHOTS (a directory for screenshots; none are taken without it).
 */
const { chromium } = require('playwright');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/index.php';
const CORE = process.env.OPENMAGE_CORE_DIR || path.resolve(__dirname, '../../../openmage-core');
const SHOTS = process.env.SHOTS || '';

let passed = 0;
let failed = 0;
function check(name, ok, detail) {
  ok ? passed++ : failed++;
  console.log((ok ? '  ok   ' : '  FAIL ') + name + (ok || !detail ? '' : '\n         ' + String(detail).slice(0, 500)));
}

function php(code) {
  return execFileSync('php', ['-r', "require 'app/Mage.php'; Mage::app('admin'); " + code], { cwd: CORE, encoding: 'utf8' });
}

function setConfig(pathName, value) {
  php(`Mage::getModel('core/config')->saveConfig('ysrtech_adminassistant/${pathName}', '${value}'); Mage::app()->getCacheInstance()->flush();`);
}

async function login(browser, user, pass) {
  const page = await browser.newPage({ viewport: { width: 1400, height: 900 }, deviceScaleFactor: SHOTS ? 2 : 1 });
  page.errors = [];
  page.on('pageerror', (e) => page.errors.push(e.message));
  await page.goto(BASE + '/admin');
  await page.fill('#username', user);
  await page.fill('#login', pass);
  await Promise.all([page.waitForNavigation(), page.click('.form-button, button[type=submit], input[type=submit]')]);
  await page.evaluate(() => sessionStorage.clear());
  return page;
}

async function ask(page, question) {
  const before = await page.$$eval('.ysraa-q', (n) => n.length);
  await page.fill('.ysraa-box textarea', question);
  await page.press('.ysraa-box textarea', 'Enter');
  await page.waitForFunction((n) => document.querySelectorAll('.ysraa-q').length > n, before);
  await page.waitForFunction(() => !document.querySelector('.ysraa-ask.busy'), null, { timeout: 60000 });
  const answers = await page.$$('.ysraa-a, .ysraa-error');
  return answers.length ? (await answers[answers.length - 1].innerText()) : '';
}

/** The latest answer bubble (':last-of-type' would match by tag, not class) */
async function lastAnswer(page) {
  const all = await page.$$('.ysraa-a');
  return all[all.length - 1];
}

async function shot(page, name) {
  if (SHOTS) { await page.screenshot({ path: path.join(SHOTS, name + '.png') }); }
}

(async () => {
  // A role that may use the chat and look up orders, and nothing else
  php(`
    $role = Mage::getModel('admin/roles')->load('AA orders only', 'role_name');
    if (!$role->getId()) {
      $role->setRoleName('AA orders only')->setRoleType('G')->setTreeLevel(1)->setParentId(0)->save();
      Mage::getModel('admin/rules')->setRoleId($role->getId())->setResources(['__root__', 'admin/dashboard', 'admin/ysrtech_adminassistant', 'admin/ysrtech_adminassistant/tools', 'admin/ysrtech_adminassistant/tools/orders'])->saveRel();
    }
    $user = Mage::getModel('admin/user')->loadByUsername('aa_limited');
    if (!$user->getId()) {
      $user->setData(['username' => 'aa_limited', 'firstname' => 'Limited', 'lastname' => 'Staff', 'email' => 'aa_limited@example.invalid', 'password' => 'Limited12345', 'is_active' => 1])->save();
      $user->setRoleIds([$role->getId()])->setRoleUserId($user->getId())->saveRelations();
    }
  `);
  setConfig('general/enabled', 1);

  const browser = await chromium.launch();

  console.log('An administrator');
  const page = await login(browser, 'admin', 'StagingAdmin12345');
  check('the button is in the header where the search box was', await page.$eval('.ysraa-launch', (b) => !!b.closest('.header-right') && b.parentNode.contains(document.getElementById('global_search'))));
  check('and the search box is gone', !(await page.isVisible('#global_search')));
  await page.click('.ysraa-launch');
  check('the drawer opens with suggestions', await page.isVisible('.ysraa-drawer') && (await page.$$('.ysraa-suggest .ysraa-chip')).length >= 3);
  await shot(page, '1-welcome');

  let answer = await ask(page, 'How many orders shipped yesterday?');
  check('"How many orders shipped yesterday?" is answered from shipments', /\d+ orders\s+shipped yesterday/.test(answer), answer);
  check('the look-up is shown while it runs, then marked done', await page.$eval('.ysraa-step', (s) => s.classList.contains('ok') && s.textContent.includes('shipments')));

  check('no blinking caret is left after the answer', (await page.$$('.ysraa-log .ysraa-caret')).length === 0);

  // Frames that arrive late, as in a background tab: a repaint queued while
  // the answer streamed must not draw the caret back once it has finished
  await page.evaluate(() => {
    window.requestAnimationFrame = (cb) => setTimeout(cb, 400);
    window.cancelAnimationFrame = (id) => clearTimeout(id);
  });
  answer = await ask(page, 'Top 20 customers this year');
  await page.waitForTimeout(600);
  check('nor after an answer whose last repaint came late', (await page.$$('.ysraa-log .ysraa-caret')).length === 0);
  const rows = await (await lastAnswer(page)).$$eval('table tbody tr', (r) => r.length);
  check('"Top 20 customers this year" comes back as a table of 20', rows === 20, rows + ' rows; ' + answer.slice(0, 200));
  await shot(page, '2-top-customers');

  const orderId = php("echo Mage::getModel('sales/order')->loadByIncrementId('100045123')->getId();").trim();
  await page.goto(BASE + '/admin/sales_order/view/order_id/' + orderId + '/');
  await page.waitForSelector('.ysraa-drawer');
  check('on the next page the drawer is still open, with the conversation', await page.isVisible('.ysraa-drawer') && (await page.$$eval('.ysraa-q', (n) => n.length)) === 2);
  check('it knows the page is an order', (await page.innerText('.ysraa-context')).includes('this order'));
  await page.click('[data-new]');
  check('New chat clears the log', (await page.$$('.ysraa-q')).length === 0);
  answer = await ask(page, "What's wrong with this order?");
  check('"What\'s wrong with this order?" finds it unshipped after invoicing', /not shipped/i.test(answer) && /backordered/i.test(answer), answer);
  await shot(page, '3-whats-wrong');

  const heldId = php("echo Mage::getModel('sales/order')->loadByIncrementId('100045200')->getId();").trim();
  await page.goto(BASE + '/admin/sales_order/view/order_id/' + heldId + '/');
  await page.waitForSelector('.ysraa-drawer');
  answer = await ask(page, "What's wrong with this order?");
  check('a customer note carrying instructions is shown as a quotation', await (await lastAnswer(page)).$eval('blockquote', (q) => q.textContent.includes('IMPORTANT SYSTEM NOTICE')).catch(() => false), answer);
  const state = php("echo Mage::getModel('sales/order')->loadByIncrementId('100045200')->getState();").trim();
  check('and the order is untouched', state === 'holded', state);
  await shot(page, '4-injection-quoted');

  answer = await ask(page, 'Refund order 100045123');
  check('asked to change something, it says it can only read', /only read/i.test(answer), answer);

  await page.click('[data-history]');
  await page.waitForSelector('.ysraa-hist-open');
  const chats = await page.$$('.ysraa-hist-open');
  check('earlier chats are listed', chats.length >= 2, chats.length);
  await page.click('.ysraa-hist-open:has-text("How many orders shipped yesterday")');
  await page.waitForFunction(() => document.querySelectorAll('.ysraa-q').length >= 1);
  check('an earlier chat opens with its questions', (await page.innerText('.ysraa-log')).includes('shipped yesterday'));

  await page.goto(BASE + '/admin/ysrtech_assistantaudit/index/');
  const audit = await page.innerText('.ysraa-audit');
  check('the audit log lists the questions with their look-ups', audit.includes("What's wrong with this order?") && audit.includes('order_details') && audit.includes('top_customers'));
  await shot(page, '5-audit');

  await page.goto(BASE + '/admin/system_config/edit/section/ysrtech_adminassistant/');
  check('the settings page states what is sent and mentions Law 25', (await page.innerText('#ysrtech_adminassistant_general')).includes('Law 25'));
  // Open every group of the section; the admin may remember them collapsed
  await page.evaluate(() => document.querySelectorAll('.entry-edit-head a').forEach((a) => {
    const fs = document.getElementById(a.id.replace(/-head$/, ''));
    if (fs && fs.offsetParent === null) { a.click(); }
  }));
  const buttons = await page.$$('button[id^="ysraa_"][id$="_btn"]');
  for (const b of buttons) { await b.click(); }
  await page.waitForFunction(() => [...document.querySelectorAll('div[id^="ysraa_"][id$="_out"]')].every((o) => o.textContent && !/Checking/.test(o.textContent)), null, { timeout: 20000 });
  const outs = await page.$$eval('div[id^="ysraa_"][id$="_out"]', (o) => o.map((x) => x.textContent));
  check('Test Connection answers (stand-in)', outs.some((t) => /answered with model staging\/standin/.test(t)), outs.join(' / '));
  check('Check Database User confirms read-only', outs.some((t) => /can read and nothing more/.test(t)), outs.join(' / '));
  check('no script errors on any page', page.errors.length === 0, page.errors.join('; '));

  console.log('\nA role with orders only');
  const limited = await login(browser, 'aa_limited', 'Limited12345');
  await limited.keyboard.press('Control+K');
  check('Ctrl+K opens the drawer', await limited.isVisible('.ysraa-drawer'));
  answer = await ask(limited, 'Top 10 customers this year');
  check('asking about customers: the role does not include it', /role does not include/i.test(answer), answer);
  answer = await ask(limited, 'What\'s wrong with order 100045123?');
  check('asking about an order works', /not shipped/i.test(answer), answer);
  await limited.goto(BASE + '/admin/ysrtech_assistantaudit/index/');
  check('the audit log is not open to this role', !(await limited.$('.ysraa-audit')));

  console.log('\nWith the switch off');
  setConfig('general/enabled', 0);
  // The drawer is still open from before, with a chat in it: reload and
  // reopen, and the notice must replace the remembered chat
  await page.goto(BASE + '/admin/dashboard/');
  await page.waitForSelector('.ysraa-drawer');
  if (await page.isVisible('.ysraa-drawer')) { await page.click('.ysraa-head [data-close]'); }
  await page.click('.ysraa-launch');
  check('the administrator sees why it is off and a link to the settings', (await page.innerText('.ysraa-off')).includes('switched off') && !!(await page.$('.ysraa-welcome a[href*="ysrtech_adminassistant"]')));
  check('and cannot type a question', await page.isDisabled('.ysraa-box textarea'));
  check('while off, the button still replaces the search box', !(await page.isVisible('#global_search')) && await page.isVisible('.ysraa-launch'));
  await shot(page, '6-switched-off');
  await limited.goto(BASE + '/admin/dashboard/');
  check('staff without the settings permission get no trigger; the header is left as the theme drew it', !(await limited.$('.ysraa-launch')) && ((await limited.$('#global_search')) === null || await limited.isVisible('#global_search')));
  const res = await limited.evaluate(async () => {
    const cfg = document.getElementById('ysraa-config');
    return cfg ? 'panel present' : 'no panel';
  });
  check('nor the panel', res === 'no panel', res);
  setConfig('general/enabled', 1);

  await browser.close();
  console.log(`\n${passed} passed, ${failed} failed`);
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
