// Genera los íconos del sitio en public/ a partir del logo del semillero (Logo_Azul.png). No forma parte del build:
// se corre a mano cuando cambia el logo. Usa sharp (dependencia de desarrollo) y no modifica el archivo de origen.
//
//   node scripts/generar-favicon.mjs RUTA/Logo_Azul.png
//
// El logo vive en Cloudinary (https://res.cloudinary.com/we3ya7sq/image/upload/v1788989589/Logo_Azul.png, el mismo de
// la animación de carga). Descárgalo a una carpeta temporal y pasa su ruta.
//
// Genera:
//   - favicon.ico           16, 32 y 48 px (entradas PNG dentro del ICO, admitidas por todos los navegadores actuales)
//   - favicon-32x32.png     32 px, fondo transparente
//   - apple-touch-icon.png  180 px, fondo blanco (iOS no admite transparencia) y un margen pequeño para las esquinas
//
// El margen transparente del logo se recorta (trim) y el resultado se centra en un cuadrado sin deformarlo.

import { mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const PUBLIC = resolve(dirname(fileURLToPath(import.meta.url)), '..', 'public');
const TRANSPARENTE = { r: 0, g: 0, b: 0, alpha: 0 };
const BLANCO = { r: 255, g: 255, b: 255, alpha: 1 };
const TAMANOS_ICO = [16, 32, 48];
const APPLE = 180;
/** Fracción del ícono de Apple que ocupa el logo (el resto es margen blanco para las esquinas redondeadas). */
const APPLE_LOGO = 0.86;

const fuente = process.argv[2];
if (!fuente) {
  console.error('Uso: node scripts/generar-favicon.mjs RUTA/Logo_Azul.png');
  process.exit(1);
}

/** Logo sin margen transparente, en un cuadrado transparente centrado (sin deformar). */
async function logoCuadrado() {
  const { data, info } = await sharp(fuente).ensureAlpha().trim({ background: TRANSPARENTE, threshold: 10 }).png().toBuffer({ resolveWithObject: true });
  const lado = Math.max(info.width, info.height);
  const cuadrado = await sharp(data)
    .extend({
      top: Math.floor((lado - info.height) / 2),
      bottom: Math.ceil((lado - info.height) / 2),
      left: Math.floor((lado - info.width) / 2),
      right: Math.ceil((lado - info.width) / 2),
      background: TRANSPARENTE,
    })
    .png()
    .toBuffer();
  return { cuadrado, recorte: `${info.width}x${info.height}` };
}

const png = (buffer, lado) =>
  sharp(buffer).resize(lado, lado, { fit: 'contain', background: TRANSPARENTE, kernel: 'lanczos3' }).png({ compressionLevel: 9 }).toBuffer();

/** ICO con entradas PNG: cabecera de 6 bytes, 16 bytes por entrada y los PNG a continuación. */
function ico(imagenes) {
  const cabecera = Buffer.alloc(6);
  cabecera.writeUInt16LE(0, 0); // reservado
  cabecera.writeUInt16LE(1, 2); // tipo 1 = ícono
  cabecera.writeUInt16LE(imagenes.length, 4);
  let desplazamiento = 6 + 16 * imagenes.length;
  const entradas = imagenes.map(({ lado, datos }) => {
    const e = Buffer.alloc(16);
    e.writeUInt8(lado >= 256 ? 0 : lado, 0); // ancho (0 = 256)
    e.writeUInt8(lado >= 256 ? 0 : lado, 1); // alto
    e.writeUInt8(0, 2); // sin paleta
    e.writeUInt8(0, 3); // reservado
    e.writeUInt16LE(1, 4); // planos
    e.writeUInt16LE(32, 6); // bits por píxel
    e.writeUInt32LE(datos.length, 8);
    e.writeUInt32LE(desplazamiento, 12);
    desplazamiento += datos.length;
    return e;
  });
  return Buffer.concat([cabecera, ...entradas, ...imagenes.map((i) => i.datos)]);
}

const { cuadrado, recorte } = await logoCuadrado();
await mkdir(PUBLIC, { recursive: true });

const icoImagenes = await Promise.all(TAMANOS_ICO.map(async (lado) => ({ lado, datos: await png(cuadrado, lado) })));
await writeFile(resolve(PUBLIC, 'favicon.ico'), ico(icoImagenes));
await writeFile(resolve(PUBLIC, 'favicon-32x32.png'), await png(cuadrado, 32));

const ladoLogo = Math.round(APPLE * APPLE_LOGO);
const margen = Math.floor((APPLE - ladoLogo) / 2);
const logoApple = await png(cuadrado, ladoLogo);
const compuesto = await sharp({ create: { width: APPLE, height: APPLE, channels: 4, background: BLANCO } })
  .composite([{ input: logoApple, top: margen, left: APPLE - ladoLogo - margen }])
  .png()
  .toBuffer();
// Segundo paso: sharp aplica flatten antes que composite; aquí se quita el canal alfa del resultado (RGB opaco).
await writeFile(resolve(PUBLIC, 'apple-touch-icon.png'), await sharp(compuesto).flatten({ background: BLANCO }).png({ compressionLevel: 9 }).toBuffer());

console.log(`Logo recortado a ${recorte} y centrado en un cuadrado.`);
console.log(`Generados en ${PUBLIC}: favicon.ico (${TAMANOS_ICO.join(', ')} px), favicon-32x32.png, apple-touch-icon.png (${APPLE} px).`);
