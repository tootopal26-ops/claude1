-- ============================================================================
-- Payment page instructions: drop the old default wording.
--
-- The old default text told customers to scan the QR code with their phone camera and then open the gateway's page. That is the
-- flow this release removes: a QR now only ever carries a real UPI payment request, and a gateway's hosted checkout is opened with a
-- button instead. Only a text that STILL EQUALS the old default is replaced, anything an admin wrote is left exactly as it is.
-- ============================================================================

UPDATE `admin_settings`
   SET `v` = '"Pay with any UPI app. Keep this page open until your payment is confirmed."'
 WHERE `k` = 'site.payment_instructions'
   AND `v` = '"Scan the QR code with your phone camera. The secure payment page opens — pay with any UPI app. Keep this page open until your payment is confirmed."';
