// Tiny bundle for the operator console: turns an otpauth:// link into an inline SVG QR code. Built to public/platform-qr.js.
import QRCode from 'qrcode';

window.PlatformQR = {
    svg: (text) => QRCode.toString(text, { type: 'svg', margin: 1, width: 208, errorCorrectionLevel: 'M' }),
};
