package uz.dtaxi.driver.ui

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.material3.Text
import androidx.compose.material3.Surface
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Arrangement
import org.maplibre.android.style.layers.SymbolLayer
import org.maplibre.android.style.layers.Property
import android.graphics.Paint
import android.graphics.Canvas
import android.graphics.Bitmap
import android.content.Context
import uz.dtaxi.driver.R
import androidx.compose.ui.unit.dp
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.Alignment
import androidx.compose.runtime.setValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.runtime.getValue
import androidx.compose.material3.SmallFloatingActionButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Icon
import androidx.compose.material.icons.filled.MyLocation
import androidx.compose.material.icons.filled.LocationSearching
import androidx.compose.material.icons.Icons
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.Box
import android.os.Bundle
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalLifecycleOwner
import androidx.compose.ui.viewinterop.AndroidView
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import org.maplibre.android.camera.CameraPosition
import org.maplibre.android.camera.CameraUpdateFactory
import org.maplibre.android.geometry.LatLng
import org.maplibre.android.geometry.LatLngBounds
import org.maplibre.android.maps.MapLibreMap
import org.maplibre.android.maps.MapView
import org.maplibre.android.maps.Style
import org.maplibre.android.style.expressions.Expression
import org.maplibre.android.style.layers.CircleLayer
import org.maplibre.android.style.layers.LineLayer
import org.maplibre.android.style.layers.PropertyFactory
import org.maplibre.android.style.sources.GeoJsonSource
import org.maplibre.geojson.Feature
import org.maplibre.geojson.FeatureCollection
import org.maplibre.geojson.LineString
import org.maplibre.geojson.Point

data class MapPoint(val lat: Double, val lng: Double)

/** Filial markazi noma'lum bo'lsa — butun O'zbekiston ko'rinadi */
internal val UZBEKISTAN_CENTER = LatLng(41.38, 64.59)
internal const val UZBEKISTAN_ZOOM = 5.0

/**
 * Ilova ichidagi xarita (qaror #3): joylashuv, A/B nuqtalar va marshrut chizig'i. Uslub — serverdan (/map/style.json).
 */
@Composable
fun TaxiMap(
    styleUrl: String,
    me: MapPoint?,
    pickup: MapPoint?,
    dropoff: MapPoint?,
    route: List<List<Double>>?,
    modifier: Modifier = Modifier,
    stops: List<MapPoint> = emptyList(),
    // Buyurtma paytida — navigator kabi: yaqin, qiya, harakat yo'nalishida, strelka pastroqda
    navigating: Boolean = false,
    bearing: Float? = null,
    speedKmh: Int = 0,
    // Filial markazi (serverdan); GPS kelguncha kamera shu yerda
    initialCenter: MapPoint? = null,
) {
    val context = LocalContext.current
    val mapView = remember { MapView(context).apply { onCreate(Bundle()) } }
    val holder = remember { arrayOfNulls<MapLibreMap>(1) }
    val lifecycle = LocalLifecycleOwner.current.lifecycle
    // Kuzatish rejimi: haydovchi xaritani surgach o'chadi, "joylashuvim" tugmasi qayta yoqadi
    var follow by remember { mutableStateOf(true) }
    var recenter by remember { mutableIntStateOf(0) }

    DisposableEffect(lifecycle) {
        // Observer qo'shilganda joriy holatgacha (START, RESUME) hodisalarni o'zi yuboradi
        var started = false
        var resumed = false
        val observer = LifecycleEventObserver { _, event ->
            when (event) {
                Lifecycle.Event.ON_START -> mapView.onStart().also { started = true }
                Lifecycle.Event.ON_RESUME -> mapView.onResume().also { resumed = true }
                Lifecycle.Event.ON_PAUSE -> mapView.onPause().also { resumed = false }
                Lifecycle.Event.ON_STOP -> mapView.onStop().also { started = false }
                else -> Unit
            }
        }
        lifecycle.addObserver(observer)
        // MainActivity ekranlarni almashtiradi (Activity yashaydi) — Home'dan chiqqanda xarita va GL yuzasi yopilmasa oqib ketardi
        onDispose {
            lifecycle.removeObserver(observer)
            if (resumed) mapView.onPause()
            if (started) mapView.onStop()
            mapView.onDestroy()
        }
    }

    Box(modifier) {
    AndroidView(factory = {
        mapView.also { view ->
            view.getMapAsync { map ->
                holder[0] = map
                map.uiSettings.isRotateGesturesEnabled = false
                map.uiSettings.isCompassEnabled = false
                map.addOnCameraMoveStartedListener { reason ->
                    if (reason == MapLibreMap.OnCameraMoveStartedListener.REASON_API_GESTURE) follow = false
                }
                map.cameraPosition = CameraPosition.Builder()
                    .target(initialCenter?.let { LatLng(it.lat, it.lng) } ?: UZBEKISTAN_CENTER)
                    .zoom(if (initialCenter != null) 13.0 else UZBEKISTAN_ZOOM).build()
                map.setStyle(Style.Builder().fromUri(styleUrl)) { style -> addLayers(style, view.context) }
            }
        }
    }, modifier = Modifier.fillMaxSize())

    if (navigating && me != null) {
        Surface(
            modifier = Modifier.align(Alignment.CenterStart).padding(start = 12.dp).size(62.dp),
            shape = CircleShape,
            color = MaterialTheme.colorScheme.surface,
            shadowElevation = 4.dp,
            // Shahar ichidagi chegaradan (60) oshsa — qizil halqa (maket A-Trip)
            border = androidx.compose.foundation.BorderStroke(
                if (speedKmh > 60) 4.dp else 1.dp,
                if (speedKmh > 60) Color(0xFFE5484D) else MaterialTheme.colorScheme.outline,
            ),
        ) {
            Column(horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
                Text("$speedKmh", style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.Bold)
                Text(stringResource(R.string.kmh), style = MaterialTheme.typography.labelSmall)
            }
        }
    }

    SmallFloatingActionButton(
        onClick = {
            follow = true
            recenter++
        },
        modifier = Modifier.align(Alignment.CenterEnd).padding(end = 12.dp).size(52.dp),
        shape = androidx.compose.foundation.shape.RoundedCornerShape(16.dp),
        containerColor = MaterialTheme.colorScheme.surface,
        // Ko'tarilgan tugmaga Material sariq tus bermasin — tungi rejimda dog'dek ko'rinardi
        elevation = androidx.compose.material3.FloatingActionButtonDefaults.elevation(4.dp, 4.dp, 4.dp, 4.dp),
    ) {
        Icon(
            if (follow) Icons.Default.MyLocation else Icons.Default.LocationSearching,
            contentDescription = stringResource(R.string.my_location),
            // GPS hali topilmagan — xira, lekin ikkala rejimda ko'rinadi
            tint = when {
                me == null -> MaterialTheme.colorScheme.onSurface.copy(alpha = 0.45f)
                follow -> MaterialTheme.colorScheme.primary
                else -> MaterialTheme.colorScheme.onSurface
            },
        )
    }
    }

    LaunchedEffect(me, pickup, dropoff, route, recenter, stops, navigating, bearing) {
        val map = holder[0] ?: return@LaunchedEffect
        val style = map.style ?: return@LaunchedEffect
        update(style, me, pickup, dropoff, route, stops, if (navigating) bearing ?: 0f else null)
        if (!follow) return@LaunchedEffect

        if (navigating && me != null) {
            // Strelka ekranning pastroq qismida (pastki buyurtma kartasidan yuqorida) — oldindagi yo'l ko'proq ko'rinsin
            val top = mapView.height * 0.40
            val bottom = mapView.height * 0.27
            val camera = CameraPosition.Builder()
                .target(LatLng(me.lat, me.lng))
                .zoom(if (speedKmh > 60) 16.5 else 17.5)
                .tilt(55.0)
                .bearing((bearing ?: map.cameraPosition.bearing.toFloat()).toDouble())
                .padding(0.0, top, 0.0, bottom)
                .build()
            map.animateCamera(CameraUpdateFactory.newCameraPosition(camera), 900)
            return@LaunchedEffect
        }

        // Navigatordan chiqqanda — tekis va shimol tepada
        if (map.cameraPosition.tilt > 0 || map.cameraPosition.bearing != 0.0) {
            map.cameraPosition = CameraPosition.Builder(map.cameraPosition).tilt(0.0).bearing(0.0).padding(0.0, 0.0, 0.0, 0.0).build()
        }

        // Tugma bosilganda — haydovchining o'ziga, uy raqamlari ko'rinadigan zoom'gacha
        if (recenter > 0 && me != null && pickup == null && dropoff == null) {
            map.animateCamera(CameraUpdateFactory.newLatLngZoom(LatLng(me.lat, me.lng), 17.0))
            return@LaunchedEffect
        }

        val points = listOfNotNull(me, pickup, dropoff) + stops
        if (points.size >= 2) {
            val bounds = LatLngBounds.Builder().apply { points.forEach { include(LatLng(it.lat, it.lng)) } }.build()
            map.easeCamera(CameraUpdateFactory.newLatLngBounds(bounds, 120))
        } else if (me != null) {
            map.easeCamera(CameraUpdateFactory.newLatLng(LatLng(me.lat, me.lng)))
        }
    }
}

private fun addLayers(style: Style, context: Context) {
    style.addImage("nav-arrow", navArrow(context))
    style.addSource(GeoJsonSource("route"))
    style.addSource(GeoJsonSource("points"))
    style.addSource(GeoJsonSource("me"))
    // Navigatordagidek qalin chiziq oq hoshiya bilan
    style.addLayer(
        LineLayer("route-casing", "route").withProperties(
            PropertyFactory.lineColor("#ffffff"),
            PropertyFactory.lineWidth(11f),
            PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
            PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
        ),
    )
    style.addLayer(
        LineLayer("route", "route").withProperties(
            PropertyFactory.lineColor("#2563eb"),
            PropertyFactory.lineWidth(7f),
            PropertyFactory.lineCap(Property.LINE_CAP_ROUND),
            PropertyFactory.lineJoin(Property.LINE_JOIN_ROUND),
        ),
    )
    style.addLayer(
        CircleLayer("points", "points").withProperties(
            PropertyFactory.circleRadius(9f),
            PropertyFactory.circleStrokeWidth(3f),
            PropertyFactory.circleStrokeColor("#ffffff"),
            PropertyFactory.circleColor(
                Expression.match(
                    Expression.get("kind"),
                    Expression.literal("#16a34a"),
                    Expression.stop("me", "#16a34a"),
                    Expression.stop("pickup", "#2563eb"),
                    Expression.stop("dropoff", "#9333ea"),
                    Expression.stop("stop", "#f59e0b"),
                ),
            ),
        ),
    )
    // Haydovchi strelkasi — xaritaga "yotqizilgan", yo'nalish bo'yicha buriladi
    style.addLayer(
        SymbolLayer("me-arrow", "me").withProperties(
            PropertyFactory.iconImage("nav-arrow"),
            PropertyFactory.iconRotate(Expression.get("bearing")),
            PropertyFactory.iconRotationAlignment(Property.ICON_ROTATION_ALIGNMENT_MAP),
            PropertyFactory.iconPitchAlignment(Property.ICON_PITCH_ALIGNMENT_MAP),
            PropertyFactory.iconAllowOverlap(true),
            PropertyFactory.iconIgnorePlacement(true),
            PropertyFactory.iconSize(0.6f),
        ),
    )
}

/** navBearing != null — navigator rejimi: "me" yashil nuqta emas, strelka */
private fun update(style: Style, me: MapPoint?, pickup: MapPoint?, dropoff: MapPoint?, route: List<List<Double>>?, stops: List<MapPoint>, navBearing: Float?) {
    val features = listOfNotNull(
        me?.takeIf { navBearing == null }?.let { feature(it, "me") },
        pickup?.let { feature(it, "pickup") },
        dropoff?.let { feature(it, "dropoff") },
    ) + stops.map { feature(it, "stop") }
    style.getSourceAs<GeoJsonSource>("points")?.setGeoJson(FeatureCollection.fromFeatures(features))
    style.getSourceAs<GeoJsonSource>("me")?.setGeoJson(
        FeatureCollection.fromFeatures(
            listOfNotNull(
                me?.takeIf { navBearing != null }?.let {
                    feature(it, "me").apply { addNumberProperty("bearing", navBearing) }
                },
            ),
        ),
    )
    val line = route?.takeIf { it.size >= 2 }?.map { Point.fromLngLat(it[0], it[1]) }
    style.getSourceAs<GeoJsonSource>("route")?.setGeoJson(
        FeatureCollection.fromFeatures(line?.let { listOf(Feature.fromGeometry(LineString.fromLngLats(it))) } ?: emptyList()),
    )
}

/** Navigator uchun sariq strelka (o'q uchi tepaga) */
private fun navArrow(context: Context): Bitmap {
    val size = (64 * context.resources.displayMetrics.density).toInt()
    val bitmap = Bitmap.createBitmap(size, size, Bitmap.Config.ARGB_8888)
    val canvas = Canvas(bitmap)
    val path = android.graphics.Path().apply {
        moveTo(size * 0.5f, size * 0.08f)
        lineTo(size * 0.9f, size * 0.88f)
        lineTo(size * 0.5f, size * 0.7f)
        lineTo(size * 0.1f, size * 0.88f)
        close()
    }
    canvas.drawPath(path, Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFFF5B400.toInt(); style = Paint.Style.FILL; setShadowLayer(size * 0.05f, 0f, size * 0.02f, 0x66000000) })
    canvas.drawPath(path, Paint(Paint.ANTI_ALIAS_FLAG).apply { color = 0xFFB37F00.toInt(); style = Paint.Style.STROKE; strokeWidth = size * 0.03f; strokeJoin = Paint.Join.ROUND })
    return bitmap
}

private fun feature(point: MapPoint, kind: String) =
    Feature.fromGeometry(Point.fromLngLat(point.lng, point.lat)).apply { addStringProperty("kind", kind) }
