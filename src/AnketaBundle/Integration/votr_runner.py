
import os
import sys
import json
from os.path import join, dirname, abspath

anketa_root = join(dirname(abspath(__file__)), '../../..')
votr_root = join(anketa_root, 'vendor/svt/votr')

sys.path.insert(0, votr_root)

# --------------------------------------------------

def main():
    from fladgejt.login import create_client

    json_input = json.load(sys.stdin)
    fakulta = json_input['fakulta']
    semestre = json_input['semestre']
    relevantne_roky = [ak_rok for ak_rok, sem in semestre]

    client = create_client(json_input['server'], json_input['params'])

#    full_name = client.get_full_name()   # TODO
    is_student = False
    subjects = []

    if client.get_som_student():
        studia = client.get_studia()
        for studium in studia:
            if studium.sp_skratka == 'ZEkP' and not studium.organizacna_jednotka: studium = studium._replace(organizacna_jednotka='PriF')
            if fakulta and studium.organizacna_jednotka != fakulta: continue   # TODO: pouzivat zapisny_list.organizacna_jednotka, ked bude v REST API
            for zapisny_list in client.get_zapisne_listy(studium.studium_key):
                if zapisny_list.akademicky_rok not in relevantne_roky: continue
                is_student = True
                for predmet in client.get_hodnotenia(zapisny_list.zapisny_list_key)[0]:
                    if [zapisny_list.akademicky_rok, predmet.semester] not in semestre: continue
                    subjects.append(dict(
                        skratka=predmet.skratka,
                        nazov=predmet.nazov,
                        semester=predmet.semester,
                        akRok=zapisny_list.akademicky_rok,
                        rokStudia=zapisny_list.rocnik,
                        studijnyProgram=dict(skratka=studium.sp_skratka, nazov=studium.sp_popis),
                    ))

    client.logout()

    result = {}
#    result['full_name'] = full_name
    result['is_student'] = is_student
    result['subjects'] = subjects

    print(json.dumps(result))

if __name__ == '__main__':
    main()

