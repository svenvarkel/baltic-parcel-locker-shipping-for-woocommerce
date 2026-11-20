# Case study: every prompt that built this plugin

This is the complete list of what the human typed into Claude Code to get from "nothing" to
"v0.1.1 running in production on herald.ee". The prompts are copied verbatim from the session
log, in the original Estonian, with an English translation underneath. Nothing was left out
except one duplicate message (the same question sent twice) and one pasted value that
identifies a customer account.

**The numbers (2026-11-20, Europe/Tallinn):**

| | |
|---|---|
| Wall clock, first prompt to production | 10:41 → 14:02, **3 h 21 min** |
| Prompts typed by the human | 39, most of them one line |
| Code written by the human | 0 lines |
| Result | ~940 lines of PHP plugin code, plus 43 self-check asserts, a carrier API mock, an Estonian translation and a README |
| Second opinion | OpenAI Codex reviewed the plan, the diff and the whole repo; its findings were checked and fixed |

During the same 3 h 21 min the human was mostly busy elsewhere: phoning and emailing Omniva and
SmartPosti to get API credentials, which turned out to be the hard part.

What the agent did between the prompts is summarised in *italics*. It read the carrier docs
in Chrome, wrote the plan, had Codex review it, wrote the code (partly through sub-agents), tested
it end-to-end in the browser against a local mock, published the repo, and deployed to production
with a rollback script.

---

## 1. Kick-off

> **10:41** Hei. Mida sellest projektist tead?
>
> *Hi. What do you know about this project?*

> **10:44** OK, mingit vana sessiooni resumeda meil pole siin. Tänane ülesanne on Chrome abil (sest nad ei ole teinud masinloetavad dokumentatsiooni!) läbi lugeda Omniva ja SmartPosti API dokumentatsioonid ja need endale selgeks teha siin:
>
> https://www.omniva.ee/ariklient/api/
>
> https://assets.ctfassets.net/dvxpcmq06s7e/61DI5QC6Tm1TgVaJHrCGEa/508ff04865e96274e67e2d29e18834b9/SmartPosti_API_Documentation_v1_7.1.pdf
>
> Herald / BEsat OÜ kasutab neid WooCommerce'is pakkide saatmiseks.
>
> Probleem on puuduvates API võtmetes, mida ma püüan kusagilt leida / saada, aga see on nende firmade puhul tehtud erakordselt tüütuks ja keerulisek, sest need ei ole normaalsed startupid, vaid vanaaegsed p***** p*****.
>
> *OK, there's no old session to resume. Today's task: use Chrome (because they haven't published machine-readable docs!) to read the Omniva and SmartPosti API documentation and understand it. Herald / BEsat OÜ uses them in WooCommerce to ship parcels. The problem is the missing API keys, which I'm trying to obtain, but these companies make that extremely tedious, because they're not normal startups but old-fashioned w***** o*****.*

> **10:45** Võta aega kõikidele selle projektiga seotud tegevustele kulunud aega ka, palun! :)
>
> *Please also keep track of the time spent on everything in this project! :)*

> **10:45** Kuule , on olemas midagi:
>
> https://developer.omniva.ee/
>
> *Hey, there is something: (link)*

> **10:48** jah, praegune sait ei ole integreeritud selles mõttes. Praeguses saiidis on mingi Woo plugin, mis pakub SmartPosti ja Omniva saatmisvõimalust koos nende pakiautomaatide loendiga, aga kui me teeme oma plugina, saame ehk sellest ka lahti?
>
> my.smartposti.ee spekk valetab - seal EI Ole võtmeid :( Uurin veel
>
> *Yes, the current site isn't integrated in that sense. It has some Woo plugin that offers SmartPost and Omniva shipping with their parcel locker lists, but if we build our own plugin, maybe we can get rid of that one too? The my.smartposti.ee spec lies: there are NO keys there :( Still looking.*

## 2. The actual task

> **10:51** Mu virisevast LinkedIni postitusest alguse saanud algne ülesanne oli see:
>
> Mu eesmärk on antud juhul lihtne:
> "Hei, Claude Code, siin on Omniva ja SmartPosti API spekid. Palun tee nendele Wordpressi / WooCommerce plugin, et ma saaks Heraldi e-poe haldusliideses tellimusele ühe klikiga genereerida vastava logistikafirma saadetise koos prinditava pakikaardi ja/või SMS pakikoodiga (Omniva pakub head pakikoodi varianti ja enda masinast pakikaardi printimist, SmartPosti oma jätab soovida). API võtmete sisestamiseks tee sama plugina settingute alla vastav koht Wordpressi adminnis ja vaata, et need saaks salvestatud krüpteeritult või kuidas iganes wp neid asju oma kõhus hoiab. Arenduseks kasuta me vana head lokaalset arenduskeskkonda, aja docker ja baas käima jne. Testi ise brauseris üle, Chrome on lahti.".
>
> (ma lähen võtmeid hankima, sa võid senikaua esimesed versioonid valmis teha!!!)
>
> *The original task, born from my whiny LinkedIn post: "Hey Claude Code, here are the Omniva and SmartPosti API specs. Please build a WordPress / WooCommerce plugin so that in Herald's shop admin I can generate the carrier's shipment for an order with one click, with a printable parcel label and/or SMS parcel code (Omniva has a good parcel-code option and label printing at the machine; SmartPosti's is lacking). Put a place for the API keys under the plugin's settings in WP admin and make sure they're stored encrypted, or however WP keeps such things. Use our good old local dev environment, start docker and the DB etc. Test it yourself in the browser, Chrome is open."*
>
> *(I'm off to get the keys, meanwhile you can build the first versions!!!)*

*Agent: wrote a plan (acceptance criteria, verification, rollback), had Codex review it, started
the implementation in a git worktree, and built a local mock of both carrier APIs so it could
test without credentials.*

> **10:59** lokaalne woo ja kõüik tuleb ära upgradeda
>
> **10:59** samale tasemel, mis prod
>
> **10:59** kui vaja. lihtsalt rsynci prod localisse
>
> *Local Woo and everything needs upgrading. To the same level as prod. If needed, just rsync prod to local.*

> **11:06** sorry, segasin vahele - logi ise sisse testkasutajaga
>
> *Sorry, I interrupted: log in yourself with the test user.*

*Agent: synced the local site with production, fixed what the sync broke, then tested in Chrome:
settings encryption, the checkout locker picker, creating a shipment, the label, carrier errors,
timeouts, and a double click (the plugin must never create the same parcel twice).*

## 3. Going public

> **11:22** (kui võimalik, paneme need pluginad eraldi github reposse ja teeme avalikuks, sest ma tahan AI-skeptikutele anda kondi hambusse, et nad saaks seda 100% AI kirjutatud koodi kritiseerida :) )
>
> *(If possible, let's put these plugins in a separate GitHub repo and make it public, because I want to give AI skeptics a bone to chew on so they can criticise this 100% AI-written code :) )*

> **11:28** palju siiani aega on läinud?:)
>
> *How much time so far? :)*

> **11:42** ok, teen repod ära. Kas GPL-2.0+ on parem kui Apache v MIT ja miks? Paku head repode nimed, ma teen svenvarkel alla
>
> *OK, I'll create the repos. Is GPL-2.0+ better than Apache or MIT, and why? Suggest good repo names, I'll create them under svenvarkel.*

> **11:45** selge, teeme GPL-2.0+. Nimeta ka kohaik ringi, see ei pea olema "herald-shipping", vaid pigem siis "baltic-parcel-locker-shipping-for-woocommerce" vms. Pikk, aga ega paremat vist ka pole
>
> *Got it, GPL-2.0+. Rename it right away too: not "herald-shipping" but something like "baltic-parcel-locker-shipping-for-woocommerce". Long, but there's probably nothing better.*

> **11:48** Remote on nüüd see: git@github.com:svenvarkel/baltic-parcel-locker-shipping-for-woocommerce.git
>
> Ise vaata, kuidas handlid seda
>
> *The remote is now this. You figure out how to handle it.*

> **11:53** kas GPL 2 on parem kui 3?
>
> *Is GPL 2 better than 3?*

> **11:54** aa okei, 2 or later, got it. Ma enne sain aru, et 2.0+ ehk et peab olema 2-st suurem:) My bad ja kõik ok
>
> *Ah OK, "2 or later", got it. I thought 2.0+ meant it had to be bigger than 2 :) My bad, all OK.*

## 4. Credentials arrive

> **11:56** omnivalt sain juba api username ja passwd ka kätte - kuhu need sisestada nüüd?
>
> *Got the API username and password from Omniva. Where do I enter them?*

> **12:00** salvestasin ära sinna LIVE võtmed. Kliendikood on vist see: *(a value pasted from browser devtools; redacted)*
>
> **12:00** idk
>
> **12:01** ma ei näe omniva iseteennduses kusagil "Kliendikoodi"
>
> *Saved the LIVE keys. The customer code is maybe this one. idk. I can't see a "customer code" anywhere in Omniva self-service.*

*Agent: probed the live Omniva API read-only and found that the "customer code" is the API
username. That isn't documented anywhere; it's now in the README.*

> **12:12** päris saadetise testimisega ootame järgmise päris tellimuseni ja siis testime selle ära, ma arvan
>
> *I think we'll wait for the next real order to test a real shipment.*

> **12:13** Pane seadetesse see hint jah, thx.
>
> Kuhu ma selle Besat OÜ ja oma tel nr pean sisestama?
>
> *Yes, put that hint in the settings, thx. Where do I enter Besat OÜ and my phone number?*

> **12:14** leidsin ja sisestasin "Saatja" andmed devis ära
>
> *Found it and entered the "Sender" details in dev.*

## 5. Production

> **12:14** kas see plugin oskab ise kuvada ka pakiautomaatide nimekirja ja me võime selle teise, vana plugin välja lülitada?
>
> *Can this plugin show the parcel locker list itself, so we can switch off the other, old plugin?*

> **12:17** jah, teeme kõik korraga ära. St et tee ise ära kõik, mis saad
>
> *Yes, let's do it all at once. I.e. do everything you can yourself.*

> **12:17** logisin Chromes prodi adminni sisse ka, kui vaja peaks olema
>
> *I've logged into prod admin in Chrome too, in case it's needed.*

*Agent: wrote a deploy plan and had Codex review it. Snapshotted the production shipping
configuration, deployed the plugin, recreated every shipping method with identical titles and
prices, disabled the old plugin, and verified rates per country through the CLI and the checkout
in the browser. It left a one-command rollback script on the server.*

> **12:32** sisestasin Saatja ja Omniva võtmed ära prodis
>
> **12:32** ootan smartpostilt API key'd
>
> *Entered the Sender details and Omniva keys in prod. Waiting for the SmartPost API key.*

## 6. Release and review

> **12:34** kuidas teised kasutajad seda pluginat endale installida saavad? Kas see ilmub kuidagi sinna Wordpressi "Add plugins" alla ka vms? Kuidas see käib?
>
> *How can other people install this plugin? Does it show up under WordPress "Add plugins" or something? How does that work?*

> **12:39** jah, teeme praegu GH release ja Wordpressi esitame pärast seda, kui oleme prod testid läbi teinud nii Omniva kui SmartPostiga
>
> *Yes, let's do a GitHub release now and submit to WordPress after we've done the prod tests with both Omniva and SmartPost.*

> **12:40** ja lase Codexil teha ka põhjalik ponytail-codereview kogu sellele asjale!
>
> *And have Codex do a thorough "ponytail" (over-engineering) review of the whole thing!*

> **12:52** kohe login chromes githubi, hetk
>
> **12:53** sees
>
> *Logging into GitHub in Chrome, one sec. In.*

*Agent: removed what the review flagged as over-engineered, released
v0.1.1 with a zip on GitHub, and deployed it to production.*

> **13:09** sain ka Smartposti API key kätte
>
> **13:10** salvestasin ära prodis selle smartposti api võtme
>
> *Got the SmartPost API key too. Saved it in prod.*

> **13:31** jah, uksekoodi teenus on mul aktiveeritud ja S on mul tavaline suurus
>
> *Yes, the door-code service is activated and S is my usual parcel size.*

> **13:32** las e-financials hetkel jääb, aga syngi prod ära
>
> *Leave e-financials [another plugin] for now, but sync prod.*

> **14:02** superlux, commit ja push ka sellele repole siin
>
> *Superb, commit and push this repo too.*

---

## What the human actually did

- Described the goal in one paragraph, as you would to a colleague.
- Got the API credentials from the carriers and typed them into the WordPress admin. The agent
  never saw them.
- Answered a few yes/no questions (license, repo name, parcel size, "do it all at once").
- Logged into GitHub and WordPress in the browser when asked.

## Credit where credit is due

The outhouse remark above was made in the heat of the moment, before the project was finished. In the end:

- **Omniva** has a genuinely good developer site, [developer.omniva.ee](https://developer.omniva.ee/), with an
  OpenAPI spec. You can't create API credentials yourself, though: they come from Omniva business support.
- **SmartPosti** does let you create the API key yourself, in business self-service (Settings, account owner
  only). It is just very well hidden behind terrible UX, and the "machine-readable" documentation is a PDF.

## What is still unproven

The plugin hasn't shipped a real parcel yet; the first real order will be the test. See
[Open questions](README.md#open-questions) for what the carrier documentation left unclear.
If you find something wrong, [open an issue](https://github.com/svenvarkel/baltic-parcel-locker-shipping-for-woocommerce/issues).
