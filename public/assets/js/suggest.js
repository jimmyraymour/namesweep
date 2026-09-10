/**
 * suggest.js — progressive enhancement for the /suggest page.
 * If JS is off, the form still works via a plain POST.
 */
import { postFragment } from './api.js';

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
        wait.hidden = false;

        try {
            const res = await postFragment(form.action || '/suggest', form);
            const html = await res.text();
            document.querySelector('#results')?.remove();

            const holder = document.createElement('section');
            holder.id = 'results';
            holder.innerHTML = html;
            form.closest('.panel')?.after(holder);
        } catch {
            wait.textContent = 'Request failed — try again.';
        } finally {
            btn.disabled = false;
            wait.hidden = true;
        }
    });
}