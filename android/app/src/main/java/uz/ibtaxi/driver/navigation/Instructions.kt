package uz.ibtaxi.driver.navigation

import uz.ibtaxi.driver.data.NavStep
import uz.ibtaxi.driver.data.UzCyrillic

/**
 * Manevr matni (o'zbek lotin/kirill, rus, ingliz): banner va ovoz uchun.
 * Kirill — o'zbek lotin matnidan o'giriladi (ko'cha nomi OSM'dagidek qoladi).
 */
object Instructions {
    fun text(step: NavStep, locale: String, meters: Int? = null): String {
        val lang = if (locale == "oz") "uz" else locale
        val action = action(step, lang)
        val street = step.name.takeIf { it.isNotBlank() && step.type != "arrive" }
        val where = when {
            street == null -> ""
            lang == "ru" -> " на $street"
            lang == "en" -> " onto $street"
            else -> ", $street"
        }
        val text = when (meters) {
            null -> action.replaceFirstChar { it.uppercase() }
            else -> when (lang) {
                "ru" -> "Через $meters метров $action"
                "en" -> "In $meters meters $action"
                else -> "$meters metrdan keyin $action"
            }
        }
        return if (locale == "oz") UzCyrillic.convert(text) + where else text + where
    }

    private fun action(step: NavStep, lang: String): String {
        fun t(uz: String, ru: String, en: String) = when (lang) {
            "ru" -> ru
            "en" -> en
            else -> uz
        }
        return when (step.type) {
            "arrive" -> t("manzilga yetib keldingiz", "вы прибыли", "you have arrived")
            "roundabout", "rotary" -> {
                val exit = step.exit ?: 1
                t("aylanmadan $exit-chiqish", "на круге $exit-й съезд", "at the roundabout take exit $exit")
            }
            else -> when (step.modifier) {
                "left" -> t("chapga buriling", "поверните налево", "turn left")
                "right" -> t("o'ngga buriling", "поверните направо", "turn right")
                "slight left" -> t("chaproqqa yuring", "держитесь левее", "keep left")
                "slight right" -> t("o'ngroqqa yuring", "держитесь правее", "keep right")
                "sharp left" -> t("keskin chapga", "резко налево", "sharp left")
                "sharp right" -> t("keskin o'ngga", "резко направо", "sharp right")
                "uturn" -> t("orqaga buriling", "развернитесь", "make a U-turn")
                else -> t("to'g'riga", "прямо", "go straight")
            }
        }
    }
}
