// The recovery actions, shared by the Order recovery screen and the order drawer: what each one is called, what it does, and the dialog it
// needs. The server decides which actions an order offers and whether a role may use them; this module only asks for what the action needs
// (a note, a provider order id, the statement that the provider's panel was checked) and sends it.
import { uuid } from '../core/dom.js';
import { confirmDialog } from '../core/dialog.js';
import { formDialog } from './ui.js';

export const RECOVERY_ACTIONS = ['retry_submit', 'link_provider_order', 'move_to_manual', 'sync', 'mark_review', 'escalate', 'resolve_review'];

export const CATEGORY_TONE = { unknown: 'chip-bad', review: 'chip-warn', failed: 'chip-bad', sending: 'chip-warn', errors: 'chip-warn', stale: 'chip-gray', waiting: 'chip-info', retry: 'chip-info' };

const unknownOutcome = (o) => o?.failure_kind === 'ambiguous';

export const LABEL = {
  retry_submit: (o) => (unknownOutcome(o) ? 'Resend after checking…' : 'Send now'),
  link_provider_order: () => 'Link provider order…',
  move_to_manual: () => 'Move to manual queue…',
  sync: () => 'Sync with provider',
  mark_review: () => 'Mark for review…',
  escalate: () => 'Escalate…',
  resolve_review: () => 'Resolve…',
};

/** Short names for a button inside a table row (the full name is the button's tooltip and the name in the More dialog). */
export const SHORT = {
  retry_submit: (o) => (unknownOutcome(o) ? 'Resend…' : 'Send now'),
  link_provider_order: () => 'Link order…',
  move_to_manual: () => 'To manual…',
  sync: () => 'Sync',
  mark_review: () => 'Review…',
  escalate: () => 'Escalate…',
  resolve_review: () => 'Resolve…',
};

export const HELP = {
  retry_submit: (o) => (unknownOutcome(o)
    ? 'Sends the order to the provider again. Only after you have looked in the provider’s panel and found NO order for this link — otherwise the customer is delivered twice and you pay twice.'
    : 'Sends the order to the provider now instead of waiting for the next scheduled attempt.'),
  link_provider_order: () => 'The provider’s panel already has this order? Enter its order id. The provider is asked to confirm it; then the shop follows its status.',
  move_to_manual: () => 'Stops sending this order to the provider. You deliver it yourself from the manual queue.',
  sync: () => 'Asks the provider for this order’s current status now.',
  mark_review: () => 'Pauses automatic sending and keeps the order in “Needs review” until someone resolves it.',
  escalate: () => 'Like marking for review, and records who escalated it and when.',
  resolve_review: () => 'Closes the review: you dealt with it. Automatic sending of a waiting order carries on.',
};

export const ICON = { retry_submit: 'rotate-ccw', link_provider_order: 'link', move_to_manual: 'package', sync: 'refresh-cw', mark_review: 'circle-alert', escalate: 'bell-ring', resolve_review: 'circle-check' };

const fieldError = (name, message) => { const e = new Error(message); e.fields = { [name]: message }; return e; };

/**
 * Run one recovery action for an order. `send(extra, idempotencyKey)` performs the request and updates the screen; it may throw an ApiError
 * (field errors are shown inside the dialog). Resolves true when the action was done, false when the admin cancelled.
 */
export async function runRecoveryAction(order, action, send) {
  const key = uuid();
  const id = order.public_id;
  switch (action) {
    case 'retry_submit':
      if (!unknownOutcome(order)) { await send({}, key); return true; }
      return !!(await formDialog({
        title: 'Resend after checking the provider', confirmText: 'Resend order',
        message: `${id}: the provider may already have this order. Open the provider’s panel and look for this link. Resend only if there is no order for it.`,
        fields: [
          { name: 'confirm', label: 'Type CHECKED to confirm there is no order for this link at the provider', required: true, placeholder: 'CHECKED' },
          { name: 'note', label: 'What did you check?', type: 'textarea', required: true, max: 400, placeholder: 'e.g. Searched the panel for the link: no order' },
        ],
        submit: async (v) => {
          if (v.confirm.trim().toUpperCase() !== 'CHECKED') throw fieldError('confirm', 'Type CHECKED to confirm.');
          await send({ verified_absent: true, note: v.note }, key);
        },
      }));
    case 'link_provider_order':
      return !!(await formDialog({
        title: 'Link the provider order', confirmText: 'Link order',
        message: `${id}: enter the order id the provider’s panel shows for this link. The provider is asked about that id and must know it; nothing changes if it does not.`,
        fields: [
          { name: 'provider_order_id', label: 'Provider order id', required: true, max: 64, placeholder: 'e.g. 23501', hint: 'Letters, numbers, - and _.' },
          { name: 'note', label: 'Note (optional)', type: 'textarea', max: 400 },
        ],
        submit: async (v) => { await send({ provider_order_id: v.provider_order_id, note: v.note }, key); },
      }));
    case 'move_to_manual':
      if (!(await confirmDialog({ title: 'Move to the manual queue?', message: `${id} will stop going to the SMM provider and wait for you to approve and deliver it yourself. If the provider might already have it, check its panel first.`, confirmText: 'Move to manual' }))) return false;
      await send({}, key);
      return true;
    case 'sync':
      await send({}, key);
      return true;
    case 'mark_review':
      return !!(await formDialog({
        title: 'Mark for review', confirmText: 'Mark for review',
        message: `${id} stays in “Needs review” and is not sent automatically until someone resolves it.`,
        fields: [{ name: 'note', label: 'Note (optional)', type: 'textarea', max: 400 }],
        submit: async (v) => { await send({ note: v.note }, key); },
      }));
    case 'escalate':
      return !!(await formDialog({
        title: 'Escalate this order', confirmText: 'Escalate',
        message: `${id} is marked as escalated (who and when are recorded) and is not sent automatically until it is resolved.`,
        fields: [{ name: 'note', label: 'Why does it need attention?', type: 'textarea', required: true, max: 400 }],
        submit: async (v) => { await send({ note: v.note }, key); },
      }));
    case 'resolve_review':
      return !!(await formDialog({
        title: 'Resolve', confirmText: 'Mark resolved',
        message: `${id} leaves the recovery queue. Say what was done.`,
        fields: [{ name: 'note', label: 'What did you do?', type: 'textarea', required: true, max: 400 }],
        submit: async (v) => { await send({ note: v.note }, key); },
      }));
    default:
      return false;
  }
}
