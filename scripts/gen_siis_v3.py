import xml.etree.ElementTree as ET
import datetime as dt
import math

NS = "http://schemas.microsoft.com/project"
ET.register_namespace("", NS)


def E(parent, tag, text=None):
    e = ET.SubElement(parent, f"{{{NS}}}{tag}")
    if text is not None:
        e.text = str(text)
    return e


def D(d, m):
    return dt.date(2026, m, d)


def dur(days):
    return f"PT{int(days) * 8}H0M0S"


def hrs(h):
    mins = round(h * 60)
    return f"PT{mins // 60}H{mins % 60}M0S"


def cents(v):
    return int(round(v * 100))


PEOPLE = {
    "A": ("Andryw Yesid Barrera Camargo", "Líder del proyecto", "AB"),
    "R": ("Rafael Esteban Lozano Vargas", "Desarrollo Front-End", "RL"),
    "N": ("Nicolás Samuel Tinjaca Topia", "Desarrollo Back-End", "NT"),
    "L": ("Luisa Fernanda Merchán Rojas", "Bases de Datos", "LM"),
    "J": ("Juliana Rincón Bautista (Semillero SIIIS)", "Cliente / Patrocinador", "JR"),
}
TARGET_HOURS = {"A": 100, "R": 100, "N": 100, "L": 100, "J": 20}
TOOLS = ["Draw.io (Wireframes)", "LaTeX / Overleaf", "VS Code", "GitHub",
         "MongoDB Atlas", "Cloudinary", "WhatsApp", "Google Meet"]
TOOL_TASKS = {
    "Draw.io (Wireframes)": ["2.2.4", "2.3.3"],
    "LaTeX / Overleaf": ["3.1.4", "5.2"],
    "VS Code": ["3.1.1", "3.1.2"],
    "GitHub": ["3.1.1", "3.1.2", "3.1.3", "3.1.4", "3.3"],
    "MongoDB Atlas": ["3.1.2", "3.1.3"],
    "Cloudinary": ["3.1.1", "3.1.2"],
    "WhatsApp": ["3.4.3", "3.5"],
    "Google Meet": ["3.4.3", "3.5", "3.8"],
}

# code, nombre, inicio, fin, resp, participa("*"=resto del equipo), apoya, aprueba, acepta
T = [
    ("1.1", "Acta de constitución del proyecto (Charter)", D(21, 8), D(24, 8), "A", "*", "J", "J", "J"),
    ("1.2", "Registro de interesados", D(24, 8), D(30, 8), "A", "RNL", "", "A", "A"),
    ("2.1", "Plan para la dirección del proyecto", D(24, 8), D(13, 9), "A", "*", "", "J", "J"),
    ("2.2.1", "Plan de gestión del alcance", D(24, 8), D(30, 8), "A", "*", "", "A", "A"),
    ("2.2.2", "Documentación de requisitos", D(24, 8), D(30, 8), "A", "*", "J", "J", "J"),
    ("2.2.3", "Enunciado del alcance", D(31, 8), D(6, 9), "A", "*", "", "J", "J"),
    ("2.2.4", "EDT y diccionario de la EDT", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.3.1", "Plan de gestión del cronograma", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.3.2", "Lista de actividades", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.3.3", "Diagrama de red (secuencia de actividades)", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.3.4", "Estimación de duraciones", D(7, 9), D(13, 9), "A", "*", "", "A", "A"),
    ("2.3.5", "Cronograma del proyecto", D(7, 9), D(13, 9), "A", "*", "", "J", "J"),
    ("2.4.1", "Plan de gestión de costos", D(31, 8), D(13, 9), "A", "*", "", "A", "J"),
    ("2.4.2", "Estimación de costos", D(7, 9), D(13, 9), "A", "N", "", "A", "A"),
    ("2.4.3", "Presupuesto (línea base de costos)", D(7, 9), D(13, 9), "A", "", "", "A", "A"),
    ("2.5.1", "Plan de gestión de recursos", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.5.2", "Requisitos de recursos de las actividades", D(7, 9), D(13, 9), "A", "*", "", "A", "A"),
    ("2.6.1", "Plan de gestión de riesgos", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.6.2", "Registro de riesgos", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.6.3", "Matriz de probabilidad e impacto (análisis cualitativo)", D(7, 9), D(13, 9), "A", "*", "", "A", "A"),
    ("2.6.4", "Análisis cuantitativo de riesgos", D(7, 9), D(13, 9), "A", "*", "", "A", "A"),
    ("2.6.5", "Plan de respuesta a riesgos", D(7, 9), D(13, 9), "A", "*", "", "A", "A"),
    ("2.7", "Plan de gestión de la calidad", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.8", "Plan de gestión de las comunicaciones", D(31, 8), D(6, 9), "A", "*", "J", "A", "A"),
    ("2.9", "Plan de gestión de las adquisiciones", D(31, 8), D(6, 9), "A", "*", "", "A", "A"),
    ("2.10", "Plan de involucramiento de los interesados", D(31, 8), D(6, 9), "A", "", "J", "A", "A"),
    ("3.1.1", "Front-End", D(9, 10), D(22, 10), "R", "N", "", "A", "A"),
    ("3.1.2", "Back-End (API REST)", D(18, 9), D(8, 10), "N", "L", "", "A", "A"),
    ("3.1.3", "Base de datos (MongoDB Atlas)", D(8, 9), D(24, 9), "L", "N", "", "A", "A"),
    ("3.1.4", "Documentación técnica", D(13, 11), D(19, 11), "N", "RL", "", "A", "A"),
    ("3.2", "Registro de lecciones aprendidas", D(14, 9), D(15, 11), "A", "*", "", "A", "A"),
    ("3.3", "Informe de aseguramiento de la calidad", D(14, 9), D(15, 11), "A", "*", "", "A", "A"),
    ("3.4.1", "Asignaciones del equipo y recursos", D(14, 9), D(27, 9), "A", "*", "", "A", "A"),
    ("3.4.2", "Evaluación de desempeño del equipo", D(14, 9), D(4, 10), "A", "*", "", "A", "A"),
    ("3.4.3", "Registro de seguimiento del equipo", D(14, 9), D(15, 11), "A", "*", "", "A", "A"),
    ("3.5", "Actas e informes de comunicación", D(14, 9), D(15, 11), "A", "*", "J", "A", "J"),
    ("3.6", "Acuerdos de adquisición (hosting, nube, multimedia)", D(26, 10), D(8, 11), "A", "N", "", "A", "A"),
    ("3.7", "Informe de respuesta a riesgos", D(14, 9), D(15, 11), "A", "*", "", "A", "A"),
    ("3.8", "Actas de participación de interesados", D(14, 9), D(15, 11), "A", "", "J", "A", "J"),
    ("4.1", "Informe de desempeño del trabajo", D(24, 8), D(29, 11), "A", "", "", "A", "A"),
    ("4.2", "Registro de control de cambios", D(24, 8), D(29, 11), "A", "", "J", "J", "J"),
    ("4.3", "Actas de aceptación de entregables", D(26, 10), D(29, 11), "A", "", "", "J", "J"),
    ("4.4", "Resultados de control de calidad (pruebas)", D(26, 10), D(15, 11), "A", "*", "", "A", "A"),
    ("4.5.1", "Control del alcance", D(14, 9), D(22, 11), "A", "", "", "A", "A"),
    ("4.5.2", "Control del cronograma", D(24, 8), D(29, 11), "A", "", "", "A", "A"),
    ("4.5.3", "Control de costos", D(26, 10), D(29, 11), "A", "", "", "A", "A"),
    ("4.5.4", "Control de recursos", D(14, 9), D(15, 11), "A", "", "", "A", "A"),
    ("4.5.5", "Monitoreo de comunicaciones", D(24, 8), D(29, 11), "A", "", "", "A", "J"),
    ("4.5.6", "Monitoreo de riesgos", D(24, 8), D(29, 11), "A", "*", "", "A", "A"),
    ("4.5.7", "Control de adquisiciones", D(9, 11), D(29, 11), "A", "N", "", "A", "A"),
    ("4.5.8", "Monitoreo del involucramiento de interesados", D(24, 8), D(29, 11), "A", "", "", "A", "J"),
    ("5.1", "Acta de cierre del proyecto", D(27, 11), D(29, 11), "A", "", "J", "J", "J"),
    ("5.2", "Informe final del proyecto", D(23, 11), D(27, 11), "A", "*", "", "J", "J"),
    ("5.3", "Entrega final del producto y documentación", D(23, 11), D(29, 11), "A", "NR", "J", "J", "J"),
]

SUMMARIES = {
    "1": "Inicio", "2": "Planeación", "3": "Ejecución", "4": "Monitoreo y control", "5": "Cierre",
    "2.2": "Gestión del alcance", "2.3": "Gestión del cronograma", "2.4": "Gestión de costos",
    "2.5": "Gestión de recursos", "2.6": "Gestión de riesgos",
    "3.1": "Sitio web SIIIS V3", "3.4": "Gestión del equipo", "4.5": "Informes de seguimiento",
}

FIXED_COST = {"3.6": 180000}
COST_NOTE = {"3.6": "Costo estimado (editable): dominio COP 60.000 + hosting COP 120.000. "
                    "Ajustar al proveedor real (se prioriza opción gratuita o de bajo costo)."}

AUTO = "auto"
LINKS = {
    "1.2": ["1.1"], "2.1": ["1.1"], "2.2.1": ["1.1"], "2.2.2": ["1.1"],
    "2.2.3": ["2.2.1", "2.2.2"], "2.2.4": ["2.2.2"],
    "2.3.1": ["2.2.1"], "2.3.2": ["2.2.2"], "2.3.3": ["2.2.2"],
    "2.3.4": ["2.3.1", "2.3.2", "2.3.3"], "2.3.5": ["2.3.4"],
    "2.4.1": ["2.2.2"], "2.4.2": ["2.4.1"], "2.4.3": ["2.4.2"],
    "2.5.1": ["2.2.2"], "2.5.2": ["2.5.1"],
    "2.6.1": ["2.2.2"], "2.6.2": ["2.2.2"], "2.6.3": ["2.6.2"], "2.6.4": ["2.6.3"], "2.6.5": ["2.6.3"],
    "2.7": ["2.2.2"], "2.8": ["2.2.2"], "2.9": ["2.2.2"], "2.10": ["2.2.2"],
    "3.1.3": ["2.2.2"], "3.1.2": ["3.1.3"], "3.1.1": ["3.1.2"], "3.1.4": ["3.1.1"],
    "3.2": ["2.1"], "3.3": ["2.1"], "3.4.1": ["2.1"], "3.4.2": ["2.1"], "3.4.3": ["2.1"],
    "3.5": ["2.1"], "3.7": ["2.1"], "3.8": ["2.1"], "3.6": ["2.9"],
    "4.1": ["1.1"], "4.2": ["1.1"], "4.5.2": ["1.1"], "4.5.5": ["1.1"], "4.5.6": ["1.1"], "4.5.8": ["1.1"],
    "4.5.1": ["2.1"], "4.5.4": ["3.4.1"], "4.3": ["3.1.1"], "4.4": ["3.1.1"],
    "4.5.3": ["3.6"], "4.5.7": ["3.6"],
    "5.2": ["4.4"], "5.3": ["3.1.4"], "5.1": ["5.2"],
}
EXTRA_LINKS = {"5.1": [("4.3", 0, 0)]}  # fin-a-fin: el acta de cierre no termina antes que la aceptación

MILESTONES = [
    ("Firma del acta de constitución", "1.1"),
    ("EDT y diccionario de la EDT elaborados", "2.2.4"),
    ("Lista de actividades del proyecto", "2.3.2"),
    ("Diagrama de red del proyecto", "2.3.3"),
    ("Registro de riesgos elaborado", "2.6.2"),
    ("Plan para la dirección del proyecto aprobado", "2.1"),
    ("Línea base del cronograma aprobada", "2.3.5"),
    ("Presupuesto definido (línea base de costos)", "2.4.3"),
    ("Plan de respuesta a riesgos definido", "2.6.5"),
    ("Base de datos configurada y poblada en MongoDB Atlas", "3.1.3"),
    ("Equipo capacitado en las tecnologías seleccionadas", "3.4.2"),
    ("API REST funcional y documentada", "3.1.2"),
    ("Vistas del sitio maquetadas y conectadas al Back-End", "3.1.1"),
    ("Proveedor de hosting/dominio seleccionado", "3.6"),
    ("Pruebas integrales realizadas", "4.4"),
    ("Manual técnico y manual de usuario elaborados", "3.1.4"),
    ("Informe final entregado al semillero", "5.2"),
    ("Entregables validados por el semillero", "4.3"),
    ("Sitio, código y manuales entregados", "5.3"),
    ("Carta de aceptación firmada - cierre formal del proyecto", "5.1"),
]


def key(c):
    return tuple(int(x) for x in c.split("."))


rows = {r[0]: r for r in T}
codes = sorted(list(rows) + list(SUMMARIES), key=key)
uid = {c: i + 1 for i, c in enumerate(codes)}
n = len(codes)
H_UID = n + 1
for i, (nm, pc) in enumerate(MILESTONES):
    uid[f"H.{i + 1}"] = H_UID + 1 + i


def leaves_under(c):
    return [r for k, r in rows.items() if k.startswith(c + ".")]


def span(c):
    if c in rows:
        return rows[c][2], rows[c][3]
    ls = leaves_under(c)
    return min(r[2] for r in ls), max(r[3] for r in ls)


def ndays(a, b):
    return (b - a).days + 1


# ---------- asignaciones ----------
assign = {}  # code -> list of (person, weight)
for c, r in rows.items():
    code, nm, s, f, resp, parts, apoya, apr, acc = r
    d = ndays(s, f)
    w = math.sqrt(d)
    lst = [(resp, w)]
    team = [p for p in "ARNL" if p != resp] if parts == "*" else [p for p in parts if p != resp]
    for p in team:
        lst.append((p, 0.5 * w))
    if apoya:
        lst.append(("J", w))
    assign[c] = lst
tot = {p: 0 for p in PEOPLE}
for c, lst in assign.items():
    for p, w in lst:
        tot[p] += w
work = {}  # (code, person) -> hours
for c, lst in assign.items():
    for p, w in lst:
        work[(c, p)] = TARGET_HOURS[p] * w / tot[p]

person_uid = {p: i + 1 for i, p in enumerate("ARNLJ")}
tool_uid = {t: 6 + i for i, t in enumerate(TOOLS)}


def task_work(c):
    if c in rows:
        return sum(h for (cc, p), h in work.items() if cc == c)
    return sum(task_work(r[0]) for r in leaves_under(c))


def task_cost(c):
    if c in rows:
        return FIXED_COST.get(c, 0)
    return sum(task_cost(r[0]) for r in leaves_under(c))


# ---------- XML ----------
root = ET.Element(f"{{{NS}}}Project")
E(root, "SaveVersion", 12)
E(root, "UID", "siis-v3-edt")
E(root, "Name", "SIIIS_V3_Cronograma")
E(root, "Title", "Desarrollo de la Versión 3 del Sitio Web del Semillero de Investigación SIIIS")
E(root, "Author", "Equipo de desarrollo SIIIS - Grupo 1")
E(root, "CreationDate", "2026-09-19T08:00:00")
E(root, "ScheduleFromStart", 1)
E(root, "StartDate", "2026-08-21T08:00:00")
E(root, "FinishDate", "2026-11-29T17:00:00")
E(root, "CurrencyDigits", 0)
E(root, "CurrencySymbol", "$")
E(root, "CurrencyCode", "COP")
E(root, "CurrencySymbolPosition", 0)
E(root, "CalendarUID", 1)
E(root, "DefaultStartTime", "08:00:00")
E(root, "DefaultFinishTime", "17:00:00")
E(root, "MinutesPerDay", 480)
E(root, "MinutesPerWeek", 3360)
E(root, "DaysPerMonth", 30)
E(root, "DefaultTaskType", 1)
E(root, "DefaultFixedCostAccrual", 3)
E(root, "DefaultStandardRate", 0)
E(root, "DefaultOvertimeRate", 0)
E(root, "DurationFormat", 7)
E(root, "WorkFormat", 2)
E(root, "HonorConstraints", 1)
E(root, "NewTasksEffortDriven", 0)
E(root, "NewTasksEstimated", 0)
E(root, "WeekStartDay", 1)

cals = E(root, "Calendars")
cal = E(cals, "Calendar")
E(cal, "UID", 1)
E(cal, "Name", "Calendario 7 días (EDT SIIIS V3)")
E(cal, "IsBaseCalendar", 1)
wds = E(cal, "WeekDays")
for dtp in range(1, 8):
    wd = E(wds, "WeekDay")
    E(wd, "DayType", dtp)
    E(wd, "DayWorking", 1)
    wts = E(wd, "WorkingTimes")
    for a, b in (("08:00:00", "12:00:00"), ("13:00:00", "17:00:00")):
        wt = E(wts, "WorkingTime")
        E(wt, "FromTime", a)
        E(wt, "ToTime", b)

tasks = E(root, "Tasks")


def baseline(t, s, f, d, w, c):
    b = E(t, "Baseline")
    E(b, "Number", 0)
    E(b, "Start", f"{s.isoformat()}T08:00:00")
    E(b, "Finish", f"{f.isoformat()}T17:00:00")
    E(b, "Duration", dur(d))
    E(b, "DurationFormat", 7)
    E(b, "Work", hrs(w))
    E(b, "Cost", cents(c))


def add_link(t, pred_code, typ, lag):
    pl = E(t, "PredecessorLink")
    E(pl, "PredecessorUID", uid[pred_code])
    E(pl, "Type", typ)
    E(pl, "CrossProject", 0)
    E(pl, "LinkLag", lag * 4800)
    E(pl, "LagFormat", 7)


def auto_link(succ, pred):
    ss, sf = rows[succ][2], rows[succ][3]
    ps, pf = rows[pred][2], rows[pred][3]
    if ss > pf:
        return 1, 0
    return 3, (ss - ps).days


outline_idx = {}
level1_counter = {}
for c in codes:
    parts_ = c.split(".")
    lvl = len(parts_)
    t = E(tasks, "Task")
    is_sum = c in SUMMARIES
    if is_sum:
        nm = f"{c} {SUMMARIES[c]}"
        s, f = span(c)
    else:
        nm = f"{c} {rows[c][1]}"
        s, f = rows[c][2], rows[c][3]
    d = ndays(s, f)
    w = task_work(c)
    cost = task_cost(c)
    E(t, "UID", uid[c])
    E(t, "ID", uid[c])
    E(t, "Name", nm)
    E(t, "Type", 1)
    E(t, "WBS", c)
    E(t, "OutlineNumber", c)
    E(t, "OutlineLevel", lvl)
    E(t, "Priority", 500)
    E(t, "Start", f"{s.isoformat()}T08:00:00")
    E(t, "Finish", f"{f.isoformat()}T17:00:00")
    E(t, "Duration", dur(d))
    E(t, "DurationFormat", 7)
    E(t, "Work", hrs(w))
    E(t, "EffortDriven", 0)
    E(t, "Estimated", 0)
    E(t, "Milestone", 0)
    E(t, "Summary", 1 if is_sum else 0)
    E(t, "FixedCostAccrual", 3)
    E(t, "PercentComplete", 0)
    if not is_sum:
        E(t, "FixedCost", cents(FIXED_COST.get(c, 0)))
    E(t, "Cost", cents(cost))
    if not is_sum:
        E(t, "ConstraintType", 4)
        E(t, "CalendarUID", 1)
        E(t, "ConstraintDate", f"{s.isoformat()}T08:00:00")
        r = rows[c]
        note = (f"Responsable: {PEOPLE[r[4]][0]}. Aprueba: {PEOPLE[r[7]][0]}. "
                f"Acepta: {PEOPLE[r[8]][0]}. Fuente: Diccionario de la EDT (orientada a entregables).")
        if c in COST_NOTE:
            note += " " + COST_NOTE[c]
        E(t, "Notes", note)
        for pc in LINKS.get(c, []):
            typ, lag = auto_link(c, pc)
            add_link(t, pc, typ, lag)
        for pc, typ, lag in EXTRA_LINKS.get(c, []):
            add_link(t, pc, typ, lag)
    baseline(t, s, f, d, w, cost)

# Hitos
th = E(tasks, "Task")
E(th, "UID", H_UID)
E(th, "ID", H_UID)
E(th, "Name", "Hitos clave del proyecto")
E(th, "Type", 1)
E(th, "WBS", "H")
E(th, "OutlineNumber", "H")
E(th, "OutlineLevel", 1)
E(th, "Priority", 500)
ms_dates = [rows[pc][3] for _, pc in MILESTONES]
E(th, "Start", f"{min(ms_dates).isoformat()}T17:00:00")
E(th, "Finish", f"{max(ms_dates).isoformat()}T17:00:00")
E(th, "Duration", dur(ndays(min(ms_dates), max(ms_dates))))
E(th, "DurationFormat", 7)
E(th, "Milestone", 0)
E(th, "Summary", 1)
E(th, "PercentComplete", 0)
for i, (nm, pc) in enumerate(MILESTONES):
    t = E(tasks, "Task")
    u = uid[f"H.{i + 1}"]
    fdate = rows[pc][3]
    E(t, "UID", u)
    E(t, "ID", u)
    E(t, "Name", f"Hito: {nm}")
    E(t, "Type", 1)
    E(t, "WBS", f"H.{i + 1}")
    E(t, "OutlineNumber", f"H.{i + 1}")
    E(t, "OutlineLevel", 2)
    E(t, "Priority", 500)
    E(t, "Start", f"{fdate.isoformat()}T17:00:00")
    E(t, "Finish", f"{fdate.isoformat()}T17:00:00")
    E(t, "Duration", "PT0H0M0S")
    E(t, "DurationFormat", 7)
    E(t, "Milestone", 1)
    E(t, "Summary", 0)
    E(t, "PercentComplete", 0)
    E(t, "ConstraintType", 0)
    E(t, "CalendarUID", 1)
    E(t, "Notes", f"Se alcanza al terminar el paquete {pc} ({rows[pc][1]}).")
    add_link(t, pc, 1, 0)
    b = E(t, "Baseline")
    E(b, "Number", 0)
    E(b, "Start", f"{fdate.isoformat()}T17:00:00")
    E(b, "Finish", f"{fdate.isoformat()}T17:00:00")
    E(b, "Duration", "PT0H0M0S")
    E(b, "DurationFormat", 7)

res = E(root, "Resources")
for p in "ARNLJ":
    r = E(res, "Resource")
    E(r, "UID", person_uid[p])
    E(r, "ID", person_uid[p])
    E(r, "Name", PEOPLE[p][0])
    E(r, "Type", 1)
    E(r, "Initials", PEOPLE[p][2])
    E(r, "Group", "Cliente" if p == "J" else "Equipo de desarrollo")
    E(r, "MaxUnits", 1)
    E(r, "CalendarUID", 1)
    E(r, "StandardRate", 0)
    E(r, "StandardRateFormat", 2)
    E(r, "OvertimeRate", 0)
    E(r, "CostPerUse", 0)
    E(r, "Notes", PEOPLE[p][1])
for tname in TOOLS:
    r = E(res, "Resource")
    E(r, "UID", tool_uid[tname])
    E(r, "ID", tool_uid[tname])
    E(r, "Name", tname)
    E(r, "Type", 0)
    E(r, "Group", "Herramientas tecnológicas")
    E(r, "MaxUnits", 1)
    E(r, "StandardRate", 0)
    E(r, "CostPerUse", 0)

asg = E(root, "Assignments")
au = 1
for c in [x for x in codes if x in rows]:
    r = rows[c]
    s, f = r[2], r[3]
    d = ndays(s, f)
    for p, _ in assign[c]:
        h = work[(c, p)]
        a = E(asg, "Assignment")
        E(a, "UID", au)
        au += 1
        E(a, "TaskUID", uid[c])
        E(a, "ResourceUID", person_uid[p])
        E(a, "Start", f"{s.isoformat()}T08:00:00")
        E(a, "Finish", f"{f.isoformat()}T17:00:00")
        E(a, "Units", round(h / (d * 8), 4))
        E(a, "Work", hrs(h))
        E(a, "Cost", 0)
    for tname, tl in TOOL_TASKS.items():
        if c in tl:
            a = E(asg, "Assignment")
            E(a, "UID", au)
            au += 1
            E(a, "TaskUID", uid[c])
            E(a, "ResourceUID", tool_uid[tname])
            E(a, "Start", f"{s.isoformat()}T08:00:00")
            E(a, "Finish", f"{f.isoformat()}T17:00:00")
            E(a, "Units", 1)
            E(a, "Cost", 0)

ET.indent(root)
ET.ElementTree(root).write("SIIIS_V3_ProjectLibre.xml", encoding="UTF-8", xml_declaration=True)
print("tareas+resumenes:", n, "hitos:", len(MILESTONES), "asignaciones:", au - 1)
