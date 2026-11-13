"""Set de regresión del cronograma SIIIS V3. Ejecutar: python3 -m unittest -v tests/test_regresion.py"""
import csv, datetime as dt, os, unittest
import xml.etree.ElementTree as ET
NS = {'m': 'http://schemas.microsoft.com/project'}
RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
XML = os.path.join(RAIZ, 'cronograma', 'SIIIS_V3_ProjectLibre.xml')
EDT = os.path.join(RAIZ, 'tests', 'data', 'edt_esperado.csv')

def cargar():
    r = ET.parse(XML).getroot(); T = {}
    for t in r.find('m:Tasks', NS):
        g = lambda k: t.find('m:' + k, NS).text if t.find('m:' + k, NS) is not None else None
        T[g('UID')] = dict(wbs=g('WBS'), s=dt.datetime.fromisoformat(g('Start')), f=dt.datetime.fromisoformat(g('Finish')),
            summ=g('Summary') == '1', ms=g('Milestone') == '1',
            preds=[(p.find('m:PredecessorUID', NS).text, int(p.find('m:Type', NS).text)) for p in t.findall('m:PredecessorLink', NS)])
    return r, T

class Regresion(unittest.TestCase):
    @classmethod
    def setUpClass(c): c.r, c.T = cargar()
    def test_01_cobertura_hitos_y_paquetes(self):
        hojas = [v for v in self.T.values() if not v['summ'] and not v['ms']]
        self.assertEqual(len(hojas), 54); self.assertEqual(sum(v['ms'] for v in self.T.values()), 20)
    def test_02_fechas_iguales_a_la_edt(self):
        esp = {r['codigo']: (r['inicio'], r['fin']) for r in csv.DictReader(open(EDT, encoding='utf8'))}
        real = {v['wbs']: (v['s'].date().isoformat(), v['f'].date().isoformat()) for v in self.T.values() if not v['summ'] and not v['ms']}
        for cod, fechas in esp.items(): self.assertEqual(real.get(cod), fechas, f'Paquete {cod}')
    def test_03_dependencias_sin_violaciones(self):
        for v in self.T.values():
            for p, tipo in v['preds']:
                P = self.T[p]
                if tipo == 1: self.assertGreaterEqual(v['s'], P['f'].replace(hour=8) if not v['ms'] else P['f'])
    def test_04_sin_ciclos(self):
        estado = {}
        def visita(u):
            self.assertNotEqual(estado.get(u), 1, 'ciclo'); 
            if estado.get(u) == 2: return
            estado[u] = 1
            for p, _ in self.T[u]['preds']: visita(p)
            estado[u] = 2
        for u in self.T: visita(u)
    def test_05_hitos_duracion_cero(self):
        for v in self.T.values():
            if v['ms']: self.assertEqual(v['s'], v['f'])
    def test_06_sin_sobreasignacion(self):
        carga = {}
        for a in self.r.find('m:Assignments', NS):
            ru = int(a.find('m:ResourceUID', NS).text)
            if ru > 5: continue
            s = dt.datetime.fromisoformat(a.find('m:Start', NS).text).date(); f = dt.datetime.fromisoformat(a.find('m:Finish', NS).text).date()
            u = float(a.find('m:Units', NS).text); d = s
            while d <= f: carga[(ru, d)] = carga.get((ru, d), 0) + u; d += dt.timedelta(days=1)
        self.assertLessEqual(max(carga.values()), 1.0)
    def test_07_presupuesto_linea_base(self):
        total = sum(float(x.text) for x in self.r.findall('.//m:Task/m:FixedCost', NS)) / 100
        self.assertEqual(total, 180000)
    def test_08_fin_de_proyecto(self):
        self.assertEqual(max(v['f'] for v in self.T.values()).date(), dt.date(2026, 11, 29))
if __name__ == '__main__': unittest.main(verbosity=2)
