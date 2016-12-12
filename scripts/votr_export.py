#!/usr/bin/env python

import os
import sys
import json
import csv

from bs4 import BeautifulSoup
from aisikl.app import Application, assert_ops
from fladgejt.login import create_client
from fladgejt.helpers import find_option, find_row
from console import settings


def prihlas_sa():
    username = os.environ['AIS_USERNAME']
    password = os.environ['AIS_PASSWORD']
    client = create_client(settings.servers[0], dict(type='cosignpassword', username=username, password=password))
    if os.environ.get('AIS_VERBOSE'): client.context.print_logs = True
    return client


def otvor_reporty(client):
    url = '/ais/servlets/WebUIServlet?appClassName=ais.gui.rp.zs.RPZS003App&kodAplikacie=RPZS003&uiLang=SK'
    app, ops = Application.open(client.context, url)
    app.awaited_open_main_dialog(ops)
    return app


def spusti_report(client, app, vrchol_stromu, kod_zostavy, parametre, vystupny_format):
    # selectnem vrchol v strome vlavo
    app.d.dzTree.select(vrchol_stromu)

    # v tabulke vyberiem riadok s danym kodom
    app.d.sysTable.select(find_row(app.d.sysTable.all_rows(), kod=kod_zostavy))

    # v menu stlacim "Spustenie"
    with app.collect_operations() as ops:
        app.d.sysSpustenieAction.execute()

    if not parametre:
        app.awaited_open_dialog(ops)
    else:
        assert_ops(ops, 'openDialog', 'openDialog')
        app.open_dialog(*ops[0].args)
        app.open_dialog(*ops[1].args)

        # v dialogu nastavim parametre
        for index, (label, value) in enumerate(parametre):
            label_id = 'inputLabel' + str(index+1)
            input_id = 'inputTextField' + str(index+1)
            if app.d.components[label_id].text != label:
                raise AISBehaviorError('%s mal byt %r' % (label_id, label))
            app.d.components[input_id].write(value)

        if 'inputLabel' + str(len(parametre)+1) in app.d.components:
            raise AISBehaviorError('Zostava ma privela parametrov')

        # stlacim OK, dialog sa zavrie
        with app.collect_operations() as ops:
            app.d.enterButton.click()
        app.awaited_close_dialog(ops)

    # nastavim format vystupu
    app.d.vystupComboBox.select(find_option(app.d.vystupComboBox.options, title=vystupny_format))

    # NESTLACAM OK, TYM SA DIALOG ZAVRIE
    # (for fun si najdi v mailinglistoch ako na to Vinko pravidelne kazdy rok nadava)
    # miesto toho v menu stlacim "Ulozit ako"
    with app.collect_operations() as ops:
        app.d.ulozitAkoAction.execute()
    response = app.awaited_shell_exec(ops)

    # nakoniec ten dialog mozem zavriet (a pouzijem X v rohu, ako kulturny clovek)
    with app.collect_operations() as ops:
        app.d.click_close_button()
    app.awaited_close_dialog(ops)

    return response


def html_to_csv(client, text, output_filename):
    client.context.log('import', 'Parsing exported HTML')
    soup = BeautifulSoup(text)
    client.context.log('import', 'Parsed HTML data')
    table = soup.find_all('table')[-1]
    data = []
    for tr in table.find_all('tr', recursive=False):
        row = [' '.join(td.get_text().split())
               for td in tr.find_all(['th', 'td'])]
        data.append(row)
    client.context.log('import', 'Extracted table values')

    with open(output_filename, 'w', encoding='utf8', newline='') as f:
        csvwriter = csv.writer(f, delimiter=';', lineterminator='\n')
        csvwriter.writerows(data)
    client.context.log('import', 'Wrote CSV output')


def export_ucitel_predmet(args):
    akademicky_rok, fakulta, output_filename = args

    client = prihlas_sa()
    app = otvor_reporty(client)

    parametre = [
        ('Akademický rok', akademicky_rok),
        ('Fakulta', fakulta),
    ]
    response = spusti_report(client, app, 'nR0/XST/11', 'XUNIBA03', parametre, 'html')

    response.encoding = 'cp1250'
    html_to_csv(client, response.text, output_filename)


def export_pocet_studentov(args):
    akademicky_rok, fakulta, semester, typ, output_filename = args
    if typ != 'faculty' and typ != 'all':
        raise Exception("typ musi byt faculty alebo all")

    client = prihlas_sa()
    app = otvor_reporty(client)

    parametre = [
        ('Akademický rok', akademicky_rok),
        ('Smester', semester),
        ('Predmety z fakulty', fakulta),
        ('Študenti z fakulty', fakulta if typ == 'faculty' else '%'),
    ]
    response = spusti_report(client, app, 'nR0/XRH/5', 'XZAPSTU', parametre, 'tbl')

    response.encoding = 'cp1250'
    with open(output_filename, 'w', encoding='utf8') as f:
        f.write(response.text)


def export_predmet_katedra(args):
    akademicky_rok, fakulta, semester, output_filename = args

    client = prihlas_sa()
    app = client._open_register_predmetov()

    app.d.fakultaUniverzitaComboBox.select(find_option(app.d.fakultaUniverzitaComboBox.options, id=fakulta))
    app.d.akRokComboBox.select(find_option(app.d.akRokComboBox.options, id=akademicky_rok))
    app.d.semesterComboBox.select(find_option(app.d.semesterComboBox.options, id=semester))
    app.d.zobrazitPredmetyButton.click()

    with app.collect_operations() as ops:
        app.d.printButton.click()
    with app.collect_operations() as ops2:
        app.awaited_abort_box(ops)
    with app.collect_operations() as ops3:
        app.awaited_abort_box(ops2)
    response = app.awaited_shell_exec(ops3)

    response.encoding = 'utf8'
    html_to_csv(client, response.text, output_filename)


commands = {
    'ucitel-predmet': export_ucitel_predmet,
    'pocet-studentov': export_pocet_studentov,
    'predmet-katedra': export_predmet_katedra,
}


if __name__ == '__main__':
    command, *args = sys.argv[1:]
    if command not in commands: raise Exception(list(commands.keys()))
    commands[command](args)

