#!/usr/bin/env python3

import asyncio
import os
import sys
import csv
from abc import ABC

from aisikl.app import Application
from aisikl.components.action import Action
from aisikl.components.button import Button
from aisikl.components.combobox import ComboBox
from aisikl.components.label import Label
from aisikl.components.table import Table
from aisikl.components.textfield import TextField
from aisikl.components.tree import Tree
from aisikl.dialog import Dialog
from aisikl.exceptions import AISBehaviorError
from aisikl.operations import AbortBoxOp, CloseDialogOp, OpenDialogOp, ShellExecOp
from aisikl.typeutils import ct
from fladgejt.base import BaseClient
from fladgejt.login import create_client
from fladgejt.helpers import find_option, find_row
from fladgejt.webui import WebuiClient
from httpx2 import Response


class ReportsDialogsType(ABC, Dialog):
    akRokComboBox: ComboBox
    dzTree: Tree
    enterButton: Button
    fakultaUniverzitaComboBox: ComboBox
    printButton: Button
    semesterComboBox: ComboBox
    sysSpustenieAction: Action
    sysTable: Table
    ulozitAkoAction: Action
    vystupComboBox: ComboBox
    zobrazitPredmetyButton: Button


async def prihlas_sa() -> BaseClient:
    username = os.environ['AIS_USERNAME']
    password = os.environ['AIS_PASSWORD']
    if os.environ.get('AIS_VERBOSE'):
        import aisikl.context
        aisikl.context.print_logs = True
    return await create_client(
        {"login_types": ('saml_password',), "ais_url": "https://ais2.uniba.sk/"},
        {"type": "saml_password", "username": username, "password": password})


async def otvor_reporty(client: BaseClient) -> Application[ReportsDialogsType]:
    url = '/ais/servlets/WebUIServlet?appClassName=ais.gui.rp.zs.RPZS003App&kodAplikacie=RPZS003&uiLang=SK'
    app, ops = await Application[ReportsDialogsType].open(client.context, url)
    await ops.expect_one(OpenDialogOp).perform()
    return app


async def spusti_report(app: Application[ReportsDialogsType], vrchol_stromu: str, kod_zostavy: str, parametre: list[tuple[str, str]], vystupny_format: str) -> Response:
    # selectnem vrchol v strome vlavo
    await app.d.dzTree.select(vrchol_stromu)

    # v tabulke vyberiem riadok s danym kodom
    await app.d.sysTable.select(find_row(await app.d.sysTable.all_rows(), kod=kod_zostavy))

    # v menu stlacim "Spustenie"
    ops = await app.d.sysSpustenieAction.execute(return_ops=True)

    if not parametre:
        await ops.expect_one(OpenDialogOp).perform()
    else:
        op1, op2 = ops.expect_two(OpenDialogOp, OpenDialogOp)
        await op1.perform()
        await op2.perform()

        # v dialogu nastavim parametre
        for index, (label, value) in enumerate(parametre):
            label_id = 'inputLabel' + str(index+1)
            input_id = 'inputTextField' + str(index+1)
            if ct(Label, app.d.components[label_id]).text != label:
                raise AISBehaviorError('%s mal byt %r' % (label_id, label))
            await ct(TextField, app.d.components[input_id]).write(value)

        if 'inputLabel' + str(len(parametre)+1) in app.d.components:
            raise AISBehaviorError('Zostava ma privela parametrov')

        # stlacim OK, dialog sa zavrie
        ops = await app.d.enterButton.click(return_ops=True)
        await ops.expect_one(CloseDialogOp).perform()

    # nastavim format vystupu
    await app.d.vystupComboBox.select(find_option(app.d.vystupComboBox.options, title=vystupny_format))

    # NESTLACAM OK, TYM SA DIALOG ZAVRIE
    # (for fun si najdi v mailinglistoch ako na to Vinko pravidelne kazdy rok nadava)
    # miesto toho v menu stlacim "Ulozit ako"
    ops = await app.d.ulozitAkoAction.execute(return_ops=True)
    response = await ops.expect_one(ShellExecOp).download()

    # nakoniec ten dialog mozem zavriet (a pouzijem X v rohu, ako kulturny clovek)
    ops = await app.d.click_close_button(return_ops=True)
    await ops.expect_one(CloseDialogOp).perform()

    return response


async def html_to_csv(client: BaseClient, response: Response, output_filename: str):
    soup = await client.context.parse_html(response)
    table = soup.find_all('table')[-1]
    data: list[list[str]] = []
    for tr in table.find_all('tr', recursive=False):
        row = [' '.join(td.get_text().split())
               for td in tr.find_all(['th', 'td'])]
        data.append(row)
    await client.context.log('import', 'Extracted table values')

    with open(output_filename, 'w', encoding='utf8', newline='') as f:
        csvwriter = csv.writer(f, delimiter=';', lineterminator='\n')
        csvwriter.writerows(data)
    await client.context.log('import', 'Wrote CSV output')


async def export_ucitel_predmet(args: list[str]) -> None:
    akademicky_rok, fakulta, output_filename = args

    client = await prihlas_sa()
    app = await otvor_reporty(client)

    parametre = [
        ('Akademický rok', akademicky_rok),
        ('Fakulta', fakulta),
    ]
    response = await spusti_report(app, 'nR0/XST/11', 'XUNIBA03', parametre, 'html')

    assert response.encoding == 'utf-8'
    await html_to_csv(client, response, output_filename)


async def export_pocet_studentov(args: list[str]) -> None:
    akademicky_rok, fakulta, semester, typ, output_filename = args
    if typ != 'faculty' and typ != 'all':
        raise Exception("typ musi byt faculty alebo all")

    client = await prihlas_sa()
    app = await otvor_reporty(client)

    parametre = [
        ('Akademický rok', akademicky_rok),
        ('Smester', semester),
        ('Predmety z fakulty', fakulta),
        ('Študenti z fakulty', fakulta if typ == 'faculty' else '%'),
    ]
    response = await spusti_report(app, 'nR0/XRH/5', 'XZAPSTU', parametre, 'tbl')

    assert response.encoding == 'utf-8'
    with open(output_filename, 'w', encoding='utf8') as f:
        f.write(response.text)


async def export_predmet_katedra(args: list[str]) -> None:
    akademicky_rok, fakulta, semester, output_filename = args

    client = await prihlas_sa()
    app = await ct(WebuiClient, client)._open_register_predmetov()  # pyright: ignore [reportPrivateUsage]

    await app.d.fakultaUniverzitaComboBox.select(find_option(app.d.fakultaUniverzitaComboBox.options, id=fakulta))
    await app.d.akRokComboBox.select(find_option(app.d.akRokComboBox.options, id=akademicky_rok))
    await app.d.semesterComboBox.select(find_option(app.d.semesterComboBox.options, id=semester))
    await app.d.zobrazitPredmetyButton.click()

    ops = await ct(Button, app.d.components['printButton']).click(return_ops=True)
    ops = await ops.expect_one(AbortBoxOp).perform(return_ops=True)
    ops = await ops.expect_one(AbortBoxOp).perform(return_ops=True)
    response = await ops.expect_one(ShellExecOp).download()

    assert response.encoding == 'utf-8'
    await html_to_csv(client, response, output_filename)


commands = {
    'ucitel-predmet': export_ucitel_predmet,
    'pocet-studentov': export_pocet_studentov,
    'predmet-katedra': export_predmet_katedra,
}


if __name__ == '__main__':
    command, *args = sys.argv[1:]
    if command not in commands: raise Exception(list(commands.keys()))
    asyncio.run(commands[command](args))

