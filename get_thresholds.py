"""
Queries for optimal thresholds from ORES.


Usage:
    get_thresholds (-h|--help)
    get_thresholds <wiki>

Options:
    -h --help  Prints this documentation
    <wiki>     The DBname of the wiki to query thresholds for.
"""
import docopt
import requests
import json
from tabulate import tabulate

ORES_HOST = "https://ores.wikimedia.org"
PATH = "/v3/scores"
MODEL = "articletopic"
PRECISION_TARGETS = [0.7, 0.5, 0.3, 0.15]


def main(argv=None):
    args = docopt.docopt(__doc__, argv=argv)

    wiki = args['<wiki>']

    table_data = []
    for label, pop_rate in get_labels(wiki, MODEL):
        threshold, precision, recall = get_best_threshold(wiki, label)
        row = {
            "label": label,
            "pop_rate": pop_rate,
            "threshold": threshold,
            "precision": precision,
            "recall": recall
        }
        table_data.append(row)

    print(json.dumps(table_data))


def get_labels(wiki, model):
    doc = requests.get(
        ORES_HOST + PATH + "/" + wiki + "/",
        params={
            'models': MODEL,
            'model_info': "params|statistics.rates"
        }
    ).json()
    labels = doc[wiki]['models'][MODEL]['params']['labels']
    pop_rates = doc[wiki]['models'][MODEL]['statistics']['rates']['population']
    return [(l, pop_rates[l]) for l in labels]


def get_threshold(wiki, label, target):
    doc = requests.get(
        ORES_HOST + PATH + "/" + wiki + "/",
        params={
            'models': MODEL,
            'model_info': "statistics.thresholds.{0}.'maximum recall @ precision >= {1}'".format(repr(label), target)
        }
    ).json()

    thresholds = doc[wiki]['models'][MODEL]['statistics']['thresholds'][label]
    if len(thresholds) == 1 and thresholds[0] is not None:
        return thresholds[0]['threshold'], thresholds[0]['recall']
    else:
        return None, None


def get_best_threshold(wiki, label):
    for target in PRECISION_TARGETS:
        threshold, recall = get_threshold(wiki, label, target)
        if recall is not None and recall >= 0.5:
            return threshold, target, recall

    return 0.9, "< 0.15", None


if __name__ == '__main__':
    main()
