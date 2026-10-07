/**
 * payment_validators.js
 * ═════════════════════════════════════════════════════════════════════════════
 * Advanced validation for credit cards and phone numbers
 *
 * Exports:
 *   - validateVisa(cardNumber)       → { valid, brand, message }
 *   - formatCardNumber(value)        → formatted string with spaces
 *   - validatePhone(number, country) → { valid, formatted, message }
 *   - getPhoneMask(country)          → phone format pattern
 * ═════════════════════════════════════════════════════════════════════════════
 */

// ─────────────────────────────────────────────────────────────────────────────
// CREDIT CARD VALIDATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Luhn Algorithm — validates credit card checksum
 * Works for Visa, Mastercard, Amex, Discover, etc.
 */
function luhnCheck(num) {
  let arr = String(num).split('').reverse().map(Number);
  let sum = 0;
  for (let i = 0; i < arr.length; i++) {
    let digit = arr[i];
    // Every second digit from the right: double it
    if (i % 2 === 1) {
      digit *= 2;
      if (digit > 9) digit -= 9;  // Subtract 9 if result is > 9
    }
    sum += digit;
  }
  return sum % 10 === 0;
}

/**
 * Detect card brand by first 6 digits (BIN range)
 */
function detectCardBrand(cardNumber) {
  const bin = cardNumber.replace(/\D/g, '').substring(0, 6);
  if (!bin) return { brand: 'unknown', name: 'Card' };

  const num = parseInt(bin, 10);

  // Visa: 4000-4999
  if (cardNumber.startsWith('4')) {
    return { brand: 'visa', name: '💳 Visa', color: '#1A1F71' };
  }
  // Mastercard: 5100-5599, 2221-2720
  if ((num >= 510000 && num <= 559999) || (num >= 222100 && num <= 272000)) {
    return { brand: 'mastercard', name: '💳 Mastercard', color: '#EB001B' };
  }
  // American Express: 34, 37
  if (cardNumber.startsWith('34') || cardNumber.startsWith('37')) {
    return { brand: 'amex', name: '💳 American Express', color: '#006FCF' };
  }
  // Discover: 6011, 65, 3528-3589
  if (cardNumber.startsWith('6011') || cardNumber.startsWith('65') ||
      (num >= 352800 && num <= 358999)) {
    return { brand: 'discover', name: '💳 Discover', color: '#FF6000' };
  }
  // Diners Club: 36, 38, 39
  if (cardNumber.startsWith('36') || cardNumber.startsWith('38') || 
      cardNumber.startsWith('39')) {
    return { brand: 'diners', name: '💳 Diners Club', color: '#0079BE' };
  }

  return { brand: 'unknown', name: '💳 Card', color: '#999' };
}

/**
 * Comprehensive Visa validation
 * - Must start with 4 (Visa prefix)
 * - Length: 13, 16, or 19 digits
 * - Must pass Luhn check
 */
function validateVisa(cardNumber) {
  const raw = String(cardNumber).replace(/\D/g, '');

  // Check for Visa prefix
  if (!raw.startsWith('4')) {
    return {
      valid: false,
      brand: 'invalid',
      message: '❌ This does not appear to be a Visa card (must start with 4)'
    };
  }

  // Check length
  const validLengths = [13, 16, 19];
  if (!validLengths.includes(raw.length)) {
    return {
      valid: false,
      brand: 'visa',
      message: `❌ Visa cards must be 13, 16, or 19 digits (you entered ${raw.length})`
    };
  }

  // Luhn check
  if (!luhnCheck(raw)) {
    return {
      valid: false,
      brand: 'visa',
      message: '❌ Card number failed validation. Please check and try again.'
    };
  }

  return {
    valid: true,
    brand: 'visa',
    message: '✅ Valid Visa card'
  };
}

/**
 * Comprehensive card validation (any type)
 */
function validateCard(cardNumber) {
  const raw = String(cardNumber).replace(/\D/g, '');

  if (raw.length < 13 || raw.length > 19) {
    return { valid: false, message: '❌ Card must be 13–19 digits' };
  }

  if (!luhnCheck(raw)) {
    return { valid: false, message: '❌ Invalid card number (failed checksum)' };
  }

  const brand = detectCardBrand(raw);
  return { valid: true, ...brand, message: '✅ Valid ' + brand.name };
}

/**
 * Format card number: 1234 5678 9012 3456
 */
function formatCardNumber(value) {
  const raw = String(value).replace(/\D/g, '').substring(0, 19);
  return raw.replace(/(.{4})/g, '$1 ').trim();
}

// ─────────────────────────────────────────────────────────────────────────────
// PHONE NUMBER VALIDATION
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Phone validation by country
 * Supports: Egypt, USA, UK, and generic international
 */
function validatePhone(phoneNumber, country = 'EG') {
  const raw = String(phoneNumber).replace(/\D/g, '');
  country = country.toUpperCase();

  const rules = {
    'EG': {
      name: 'Egypt',
      regex: /^(201|20201)[0-9]{8,9}$|^01[0125]\d{8}$/,
      format: '01X XXXX XXXX (Egyptian)',
      example: '01001234567'
    },
    'US': {
      name: 'United States',
      regex: /^1?\d{10}$/,
      format: '+1 (XXX) XXX-XXXX (US)',
      example: '2025551234'
    },
    'UK': {
      name: 'United Kingdom',
      regex: /^(?:(?:\+|00)44|0)(?:\d{4}\s?\d{6}|\d{3}\s?\d{3}\s?\d{4}|\d{2}\s?\d{4}\s?\d{4})$/,
      format: '+44 XXXX XXXXXX (UK)',
      example: '02071838750'
    },
    'INTL': {
      name: 'International',
      regex: /^\+?[1-9]\d{1,14}$/,  // E.164 format
      format: '+XXX XXXXXXXXX',
      example: '+201001234567'
    }
  };

  const rule = rules[country] || rules['INTL'];

  if (!raw || raw.length < 7) {
    return {
      valid: false,
      formatted: '',
      message: `❌ Phone number too short (${rule.name} format: ${rule.format})`
    };
  }

  if (!rule.regex.test(raw) && !rules['INTL'].regex.test(raw)) {
    return {
      valid: false,
      formatted: '',
      message: `❌ Invalid ${rule.name} phone format. Expected: ${rule.format}\n   Example: ${rule.example}`
    };
  }

  return {
    valid: true,
    formatted: formatPhone(raw, country),
    message: `✅ Valid ${rule.name} phone number`
  };
}

/**
 * Format phone number based on country
 */
function formatPhone(raw, country = 'EG') {
  raw = raw.replace(/\D/g, '');
  country = country.toUpperCase();

  if (country === 'EG') {
    if (raw.startsWith('20')) raw = raw.substring(2);
    if (raw.startsWith('0')) raw = raw.substring(1);
    // 01X XXXX XXXX
    if (raw.length === 10) return '0' + raw.substring(0, 1) + ' ' + raw.substring(1, 5) + ' ' + raw.substring(5);
    if (raw.length === 11) return '0' + raw.substring(0, 1) + ' ' + raw.substring(1, 5) + ' ' + raw.substring(5);
    return '+20 ' + raw;
  }

  if (country === 'US') {
    raw = raw.replace(/^1/, '');
    if (raw.length === 10) return '+1 (' + raw.substring(0, 3) + ') ' + raw.substring(3, 6) + '-' + raw.substring(6);
    return '+1 ' + raw;
  }

  if (country === 'UK') {
    return '+44 ' + raw.substring(raw.startsWith('0') ? 1 : 0);
  }

  // Default: E.164
  if (!raw.startsWith('+')) return '+' + raw;
  return raw;
}

/**
 * Guess country code from phone prefix
 */
function guessPhoneCountry(phoneNumber) {
  const raw = String(phoneNumber).replace(/\D/g, '');

  if (raw.startsWith('1') || raw.length === 10) return 'US';
  if (raw.startsWith('44')) return 'UK';
  if (raw.startsWith('20') || raw.startsWith('01')) return 'EG';

  return 'INTL';
}

/**
 * Get phone format pattern for a country
 */
function getPhoneMask(country = 'EG') {
  const masks = {
    'EG': '01X XXXX XXXX',
    'US': '+1 (XXX) XXX-XXXX',
    'UK': '+44 XXXX XXXXXX',
    'INTL': '+XXX XXXXXXXXX'
  };
  return masks[country.toUpperCase()] || masks['INTL'];
}

/**
 * Validate credit card expiration date (MM/YY)
 */
function validateExpiry(expiryString) {
  const match = expiryString.match(/^(\d{2})\/(\d{2})$/);
  if (!match) {
    return { valid: false, message: '❌ Format must be MM/YY' };
  }

  const [_, mm, yy] = match;
  const month = parseInt(mm, 10);
  const year = 2000 + parseInt(yy, 10);

  if (month < 1 || month > 12) {
    return { valid: false, message: '❌ Month must be 01–12' };
  }

  // Check if card has expired
  const expiryDate = new Date(year, month, 0);  // Last day of the month
  const today = new Date();
  today.setHours(0, 0, 0, 0);

  if (expiryDate < today) {
    return { valid: false, message: '❌ Card has expired' };
  }

  return { valid: true, message: '✅ Expiry date is valid' };
}

/**
 * Validate CVV (3 or 4 digits)
 */
function validateCVV(cvv) {
  const raw = String(cvv).replace(/\D/g, '');
  if (raw.length < 3 || raw.length > 4) {
    return { valid: false, message: '❌ CVV must be 3 or 4 digits' };
  }
  return { valid: true, message: '✅ Valid CVV' };
}
