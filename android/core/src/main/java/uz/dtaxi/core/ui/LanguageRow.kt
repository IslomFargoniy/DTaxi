package uz.dtaxi.core.ui

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Button
import androidx.compose.material3.ButtonDefaults
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import uz.dtaxi.core.data.AppLocale

/**
 * Til tanlash: O'z · Ўз · Ru · En — teng kenglikda, har doim bir qatorda (tor ekranda ham).
 */
@Composable
fun LanguageRow(selected: String, onSelect: (String) -> Unit, modifier: Modifier = Modifier) {
    Row(modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        AppLocale.codes.forEach { code ->
            val label = AppLocale.shortLabels.getValue(code)
            val itemModifier = Modifier.weight(1f).height(44.dp)
            val shape = RoundedCornerShape(12.dp)
            val padding = ButtonDefaults.TextButtonContentPadding
            if (selected == code) {
                Button(onClick = {}, modifier = itemModifier, shape = shape, contentPadding = padding) {
                    Text(label, fontWeight = FontWeight.Bold)
                }
            } else {
                // Sariq yozuv oq fonda xira — tanlanmaganlari oddiy matn rangida
                OutlinedButton(
                    onClick = { onSelect(code) },
                    modifier = itemModifier,
                    shape = shape,
                    contentPadding = padding,
                    colors = ButtonDefaults.outlinedButtonColors(contentColor = MaterialTheme.colorScheme.onSurface),
                ) {
                    Text(label)
                }
            }
        }
    }
}
