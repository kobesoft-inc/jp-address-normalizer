# jp-address-normalizer

日本の住所文字列を、`postal_code`・`prefecture_code`・`city_code`・`town`・`street`・`building`
に分割するPHPライブラリです。

[jp-postal-code-db](https://github.com/kobesoft-inc/jp-postal-code-db) が配布している
SQLite3データベースを参照データとして使い、都道府県名・市区町村名・町名を正規表現の
最長一致で切り出します。

## インストール

```bash
composer require kobesoft-inc/jp-address-normalizer
```

参照データとして [jp-postal-code-db](https://github.com/kobesoft-inc/jp-postal-code-db/releases/latest) の
`jp_postal_code.db` を別途ダウンロードしてください（本パッケージには同梱していません）。

## 使い方

```php
use JpAddressNormalizer\AddressNormalizer;

$normalizer = new AddressNormalizer('/path/to/jp_postal_code.db');

$result = $normalizer->normalize(
    '〒060-0001 北海道札幌市中央区北一条西６丁目１－２アーバンネット札幌ビル２Ｆ'
);

$result->postalCode;      // '0600001'
$result->prefectureCode;  // '01'
$result->cityCode;        // '01101'
$result->town;            // '北一条西'
$result->street;          // '６丁目１－２'
$result->building;        // 'アーバンネット札幌ビル２Ｆ'

$result->toArray();       // 上記を連想配列として取得
```

すでに開いている`PDO`接続を使い回したい場合は`AddressNormalizer::fromPdo(PDO $pdo)`を使ってください。

## 分割の仕組み

1. **postal_code**: 先頭の`〒060-0001`や`060-0001`のような郵便番号表記を正規表現で抽出する。
2. **prefecture_code**: 都道府県名47件のうち、文字列の先頭に一致する最長のものを探す。
3. **city_code**: 該当都道府県内の市区町村名のうち、続く文字列の先頭に一致する最長のものを探す。
4. **town**: 該当市区町村内の町名（`postal_codes.town`の一覧）のうち、続く文字列の先頭に
   一致する最長のものを探す。
5. **street** / **building**: 町名より後ろの文字列を、数字・丁目・番地・号・線・区・「の」・
   ハイフン類が続く限りを`street`、それ以外の文字（建物名・私書箱の注記等）が現れた時点で
   以降を`building`として正規表現で分割する。

いずれかの段階で一致するものが見つからなければ、それ以降は分割せずそのまま`building`（または
該当する項目）に残す。存在しない住所や解析できない住所を渡してもエラーにはならない。

### town表記の正規化について

日本郵便のデータでも、同じ「条」のような町名について、地域によって漢数字（例:「北一条西」
札幌市中央区）と算用数字（例:「４条通」旭川市）の両方が使われており、表記が統一されていません。
本ライブラリでは、算用数字/漢数字のどちらで書かれた住所が来ても一致するように候補を用意した上で、
出力する`town`は**漢数字表記に統一**します（例: 入力が「旭川市４条通」でも`town`は「四条通」になる）。
同様に「字」「大字」の有無の違いも吸収します。

これらの表記ゆれの吸収は、あくまで現行の`postal_codes`データに存在する町名が対象です。
市町村合併前の旧地名など、現行データに存在しない表記は統合できません。

## 検証結果

[jp-postal-code-db](https://github.com/kobesoft-inc/jp-postal-code-db) の`offices`（都道府県名+
市区町村名+住所を結合して復元した文字列）と、[houjin-bangou-db](https://github.com/kobesoft-inc/houjin-bangou-db)
の法人住所（同様に復元した文字列）に対して実際に本ライブラリで解析し、解析結果の
`prefecture_code`・`city_code`が元データと一致するかを検証しています。

| データソース | 件数 | prefecture_code一致率 | city_code一致率 | town特定率 |
| --- | --- | --- | --- | --- |
| jp-postal-code-db（offices全件） | 22,419件 | 100% | 100% | 98.5% |
| houjin-bangou-db（有効な法人、ランダム2万件） | 20,000件 | 100% | 99.98% | 97.7% |

houjin-bangou-dbで一致しなかった数件は、いずれも「浜松市」「堺市」「新潟市」のように、
政令指定都市化で区が導入される前の旧市区町村名で登録されたままの法人住所でした。
現行の`postal_codes`データには区名を含まない市区町村名が存在しないため、これは
本ライブラリの制約というより、上記の通り想定内の限界です。

## ライセンス

MIT License
