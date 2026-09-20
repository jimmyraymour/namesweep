/**
 * suggest.js — progressive enhancement for the /suggest page.
 * If JS is off, the form still works via a plain POST.
 */
import { postFragment, renderFragment } from './api.js';

const WORKING = 'Working — this can take a few minutes...';

const form = document.getElementById('suggest-form');
if (form) {
    form.addEventListener('submit', async (event) => {
        const wait = document.getElementById('suggest-wait');
        const btn = document.getElementById('suggest-btn');
        event.preventDefault();
        if (btn.disabled) {
            return;
        }
        btn.disabled = true;
        wait.textContent = WORKING;
        wait.hidden = false;

        try {
            const res = await postFragment(form.action || '/suggest', form);
            renderFragment(form, await res.text(), 'results');
            wait.hidden = true;
        } catch {
            // Leave the message on screen: hiding it in finally() is what
            // made a failed round look like the button did nothing.
            wait.textContent = 'Request failed — try again.';
        } finally {
            btn.disabled = false;
        }
    });
}
