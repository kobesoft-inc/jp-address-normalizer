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
    '北海道札幌市中央区北一条西６丁目１－２アーバンネット札幌ビル２Ｆ'
);

$result->postalCode;         // '0600001'（住所に無くても、town/streetから逆引きできれば補われる）
$result->prefectureCode;     // '01'
$result->cityCode;           // '01101'
$result->town;               // '北一条西'
$result->street->raw;        // '６丁目１－２'
$result->street->chome;      // 6
$result->street->banchi;     // 1
$result->street->banchiSub;  // 2
$result->street->go;         // null
$result->street->format();   // '6丁目1-2'
$result->building;           // 'アーバンネット札幌ビル２Ｆ'

$result->toArray();          // 上記を連想配列として取得
```

すでに開いている`PDO`接続を使い回したい場合は`AddressNormalizer::fromPdo(PDO $pdo)`を使ってください。

## 分割の仕組み

1. **postal_code**: 先頭の`〒060-0001`や`060-0001`のような郵便番号表記を正規表現で抽出する。
   住所に含まれていない場合は、後述の逆引きでの補完を試みる。
2. **prefecture_code**: 都道府県名47件のうち、文字列の先頭に一致する最長のものを探す。
3. **city_code**: 該当都道府県内の市区町村名のうち、続く文字列の先頭に一致する最長のものを探す。
4. **town**: 該当市区町村内の町名（`postal_codes.town`の一覧）のうち、続く文字列の先頭に
   一致する最長のものを探す。
5. **street** / **building**: 町名より後ろの文字列を、数字・丁目・番地・号・線・区・地割・「の」・
   ハイフン類が続く限りを番地部分、それ以外の文字（建物名・私書箱の注記等）が現れた時点で
   以降を`building`として正規表現で分割する。番地部分はさらに`Street`オブジェクト
   （`chome`・`banchi`・`banchiSub`・`go`）に構造化する。

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

### Street（番地部分）の構造化について

`street`は`raw`（元の表記をそのまま保持）に加えて、`chome`・`banchi`（番地）・`banchiSub`
（番地の枝番）・`go`（号）に分解します。「地割」「線」のような北海道の開拓地番等、丁目・番地の
並びとして読み取れない特殊な表記は、無理に数字を抽出せず`raw`のみを保持します
（`chome`等は全てnullのままになりますが、エラーにはなりません）。`format()`で
統一的な表記に組み立て直すこともできます（構造化できていない場合は`raw`をそのまま返す）。

京都の通り名（「烏丸通今出川上る」等）は、丁目・番地の並びを持たないため`street`としては
構造化されず、`building`側にそのまま残ります。ただし後述の郵便番号の逆引きでは、
この通り名を手がかりに使います。

### 郵便番号の逆引きについて（ある程度）

住所文字列に郵便番号が含まれていなくても、`town`が判明すれば、多くの場合`postal_code`を
補うことができます。ただし1つの町名が複数の郵便番号に分かれているケース（同一町名内で
丁目・地区によって郵便番号が変わる）では、次の優先順で絞り込みを試みます。

1. `street.chome`（丁目番号）が、`town_details.chome_from`〜`chome_to`の範囲に一意に収まるか。
2. `town_details.detail`（元データの生テキスト。地区名や京都の通り名等）が、住所の
   残りの文字列に含まれているか。

どちらでも一意に絞り込めない場合は`postalCode`はnullのままとなり、`postalCodeCandidates`に
候補（複数の郵便番号）が入ります。誤った郵便番号を推測で1つに決め打ちすることはありません。

## 検証結果

[jp-postal-code-db](https://github.com/kobesoft-inc/jp-postal-code-db) と
[houjin-bangou-db](https://github.com/kobesoft-inc/houjin-bangou-db) の実データに対して、
本ライブラリで解析した結果が元データと一致するかを検証しています。

**都道府県・市区町村の解決精度**（都道府県名+市区町村名+住所を結合して復元した文字列を解析し、
`prefecture_code`・`city_code`が元データと一致するか）

| データソース | 件数 | prefecture_code一致率 | city_code一致率 | town特定率 |
| --- | --- | --- | --- | --- |
| jp-postal-code-db（offices全件） | 22,419件 | 100% | 100% | 98.5% |
| houjin-bangou-db（有効な法人、ランダム2万件） | 20,000件 | 100% | 99.98% | 97.7% |

houjin-bangou-dbで一致しなかった数件は、いずれも「浜松市」「堺市」「新潟市」のように、
政令指定都市化で区が導入される前の旧市区町村名で登録されたままの法人住所でした。
現行の`postal_codes`データには区名を含まない市区町村名が存在しないため、これは
本ライブラリの制約というより、上記の通り想定内の限界です。

**郵便番号の逆引き精度**（jp-postal-code-dbの`postal_codes`・`town_details`から実在する
住所を復元し、郵便番号を伏せて解析、逆引きした`postal_code`が元の値と一致するか）

| ケース | 件数 | 正解率 | 備考 |
| --- | --- | --- | --- |
| 町名が1つの郵便番号にしか対応しない（単純ケース） | 10,000件（ランダム抽出） | 95.0% | 残りは複数候補で判定不能（誤りは0件） |
| 丁目範囲で複数の郵便番号に分かれるケース | 137件（全件） | 100% | |
| 地区名・京都の通り名等、丁目番号で表現できないケース | 5,000件（ランダム抽出） | 60.6% | 39.3%は複数候補で判定不能、誤りは0.02% |

「判定不能」は、誤った郵便番号を1つに決め打ちせず`postalCodeCandidates`で候補を返している
ケースです。誤答率はいずれも非常に低く抑えられています。

## ライセンス

MIT License
