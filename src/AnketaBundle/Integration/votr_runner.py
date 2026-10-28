import asyncio
import sys
import json
from typing import cast

async def main():
    from fladgejt.login import create_client
    from fladgejt.webui import WebuiClient

    json_input = json.load(sys.stdin)
    fakulta = json_input['fakulta']
    semestre = json_input['semestre']
    relevantne_roky = [ak_rok for ak_rok, _sem in semestre]

    client = await create_client(json_input['server'], json_input['params'])
    assert isinstance(client, WebuiClient)

    is_student = False
    subjects: list[object] = []
    ostatne_studia: list[object] = []

    if await client.get_som_student():
        studia = await client.get_studia()
        for studium in studia:
            if studium["sp_skratka"] == "ZEkP" and not studium["organizacna_jednotka"]:
                studium = studium.copy()
                studium["organizacna_jednotka"] = "PriF"

            studium_key = cast("dict[str, str]", cast("object", studium))["studium_key"]  # sigh

            if fakulta and studium["organizacna_jednotka"] != fakulta:
                for zapisny_list in await client.get_zapisne_listy(studium_key):
                    # Ak je to pre nas relevantne studium, vratime ho
                    # Dolezite pri zistovani chybajucej org. jednotky v AISe
                    if zapisny_list["akademicky_rok"] in relevantne_roky:
                        ostatne_studia.append({
                            "skratka": studium["sp_skratka"] or "Neznamy program",
                            "oj": studium["organizacna_jednotka"] or "bez fakulty",
                        })
                continue   # TODO: pouzivat zapisny_list.organizacna_jednotka, ked bude v REST API

            for zapisny_list in await client.get_zapisne_listy(studium_key):
                if zapisny_list["akademicky_rok"] not in relevantne_roky: continue
                is_student = True
                zapisny_list_key = cast("dict[str, str]", cast("object", zapisny_list))["zapisny_list_key"]  # sigh
                for predmet in (await client.get_hodnotenia(zapisny_list_key))[0]:
                    if [zapisny_list["akademicky_rok"], predmet["semester"]] not in semestre: continue
                    if predmet["hodn_znamka"] == "U": continue
                    subjects.append(dict(
                        skratka=predmet["skratka"],
                        nazov=predmet["nazov"],
                        semester=predmet["semester"],
                        akRok=zapisny_list["akademicky_rok"],
                        rokStudia=zapisny_list["rocnik"],
                        studijnyProgram=dict(skratka=studium["sp_skratka"], nazov=studium["sp_popis"]),
                    ))

    await client.logout()

    result = {}
    result['is_student'] = is_student
    result['subjects'] = subjects
    result['ostatne_studia'] = ostatne_studia

    print(json.dumps(result))

if __name__ == '__main__':
    asyncio.run(main())

